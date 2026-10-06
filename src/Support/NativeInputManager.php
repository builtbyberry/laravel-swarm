<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\AuthorizesNativeAgentConversation;
use BuiltByBerry\LaravelSwarm\Contracts\AuthorizesNativeInputAttachment;
use BuiltByBerry\LaravelSwarm\Contracts\ConsumesNativeInputMessages;
use BuiltByBerry\LaravelSwarm\Contracts\NativeAgentToolFactory;
use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Files\StoredVideo;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Throwable;

final class NativeInputManager
{
    protected Container $container;

    public function __construct(
        protected ConfigRepository $config,
        protected NativeInputStore $store,
        protected FilesystemFactory $filesystems,
        protected AuthorizesNativeInputAttachment $authorizer,
        protected SwarmAuditDispatcher $audit,
        ?Container $container = null,
    ) {
        $this->container = $container ?? \Illuminate\Container\Container::getInstance();
    }

    public function admit(RunContext $context, Topology $topology, ExecutionMode $mode): void
    {
        $manifest = $context->nativeInput();
        $hasOperationalReference = $context->nativeInputReference() !== null;
        if ($manifest === null && $hasOperationalReference) {
            $this->invocation($context, '__load__', $context->input);
            $manifest = $context->nativeInput();
            if ($manifest !== null) {
                $context->input = $manifest->text;
            }
        }

        if ($manifest === null) {
            return;
        }

        if (! $hasOperationalReference && ! (bool) $this->config->get('swarm.native_inputs.enabled', false)) {
            throw new SwarmException('Native swarm input is disabled. Enable [swarm.native_inputs.enabled] only after the v1 readers and migration are deployed to every worker.');
        }

        if (! $hasOperationalReference && $this->hasSettings($manifest)
            && ! (bool) $this->config->get('swarm.native_agent_settings.enabled', false)) {
            throw new SwarmException('Native per-run agent settings are disabled. Enable [swarm.native_agent_settings.enabled] only after v2-capable readers are deployed to every worker.');
        }

        $this->applyDefaultRecipient($manifest, $topology);
        if (! $hasOperationalReference) {
            $this->freezeToolFactories($manifest);
        }
        $this->validateRecipients($manifest, $topology);
        $this->validateAttachmentLimits($manifest->attachments);
        $requiresOperationalReference = $this->requiresOperationalReference($manifest, $topology, $mode);
        if ($requiresOperationalReference) {
            $this->assertRecoverableSources($manifest->attachments);
        }
        $this->authorizeExternalAttachments($manifest, $context);
        $this->validateNativeSettings($manifest, $context, $requiresOperationalReference);

        if ($requiresOperationalReference && $this->hasConsumableMessages($manifest)
            && $this->config->get('swarm.history.driver') !== 'database') {
            throw new SwarmException('Recoverable withMessages requires [swarm.history.driver] to be [database] so terminal history and one-shot consumption commit atomically.');
        }

        if (! $requiresOperationalReference) {
            return;
        }

        if ($context->nativeInputReference() !== null) {
            return;
        }

        if ($this->config->get('swarm.persistence.driver') !== 'database'
            || ! (bool) $this->config->get('swarm.persistence.encrypt_at_rest', false)) {
            throw new SwarmException('Recoverable native swarm input requires database persistence with [swarm.persistence.encrypt_at_rest] enabled.');
        }

        $manifest->captureRecoverableInvocationOptions();
        [$manifest->attachments, $manifest->attachmentHashes, $manifest->ownedAttachmentIndexes, $promotions] = $this->planRecoverable($manifest->attachments, $context->runId);
        $promotions = array_merge($promotions, $this->planRecoverableMessages($manifest, $context->runId));
        $payload = $manifest->toArray();
        $payload['authorization'] = [
            'actor' => $context->metadata['actor'] ?? null,
            'tenant_id' => $context->metadata['tenant_id'] ?? null,
        ];
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $configuredMaxInputBytes = $this->config->get('swarm.limits.max_input_bytes');
        $maxInputBytes = is_numeric($configuredMaxInputBytes) && (int) $configuredMaxInputBytes > 0
            ? (int) $configuredMaxInputBytes
            : null;
        if ($maxInputBytes !== null && strlen($encodedPayload) > $maxInputBytes) {
            throw new SwarmException("The encoded native input operational envelope exceeds the configured [swarm.limits.max_input_bytes] limit of [{$maxInputBytes}] bytes.");
        }
        $hash = hash('sha256', $encodedPayload);
        $id = (string) Str::uuid();
        $expiresAt = time() + max(60, (int) $this->config->get('swarm.native_inputs.retention_seconds', 86400));

        $stored = false;
        try {
            $this->store->put($id, $context->runId, $payload, $hash, $expiresAt);
            $stored = true;
            $this->promote($promotions);
            $this->store->activate($id, $context->runId);
            $context->setNativeInputReference($id);
        } catch (Throwable $exception) {
            if ($stored) {
                try {
                    $this->store->revoke($id, $context->runId);
                    $this->deleteOwnedLocations($promotions);
                } catch (Throwable) {
                    // Revoke-before-delete is deliberate: if the durable
                    // locator cannot be retained, leave the bytes in place.
                }
            }

            throw $exception;
        }
    }

    public function message(RunContext $context, string $recipient, string $topologyText): string|UserMessage
    {
        return $this->invocation($context, $recipient, $topologyText)->prompt;
    }

    public function invocation(RunContext $context, string $recipient, string $topologyText, ?NativeAgentSettingsAttempt $attempt = null): NativeAgentInvocation
    {
        $manifest = $context->nativeInput();

        if (($reference = $context->nativeInputReference()) !== null) {
            $row = $this->store->find($reference);
            if ($row === null || $row['run_id'] !== $context->runId || $row['state'] !== 'active') {
                throw new SwarmException("Native input envelope [{$reference}] is missing, revoked, or does not belong to run [{$context->runId}].");
            }

            if ($row['expires_at'] <= time()) {
                throw new SwarmException("Native input envelope [{$reference}] has expired.");
            }

            $authorization = is_array($row['payload']['authorization'] ?? null) ? $row['payload']['authorization'] : [];
            if (($authorization['actor'] ?? null) !== ($context->metadata['actor'] ?? null)
                || ($authorization['tenant_id'] ?? null) !== ($context->metadata['tenant_id'] ?? null)) {
                throw new SwarmException("Native input envelope [{$reference}] is not authorized for this actor or tenant.");
            }

            $version = $row['payload']['version'] ?? 0;
            if (! in_array($version, [NativeInputManifest::VERSION, NativeInputManifest::SETTINGS_VERSION], true)) {
                throw new SwarmException("Native input envelope [{$reference}] has unsupported format version [{$version}]. Upgrade every worker before enabling new native input writers.");
            }
            $columnVersion = $row['format_version'] ?? $version;
            if ($columnVersion !== $version) {
                throw new SwarmException("Native input envelope [{$reference}] format column [{$columnVersion}] does not match sealed payload version [{$version}].");
            }

            $this->validateRecoveredDescriptors($row['payload'], $context->runId);
            $this->authorizeRecoveredMessageAttachments($row['payload'], $context);
            $manifest = NativeInputManifest::fromArray($row['payload']);
            $this->validateAttachmentLimits($manifest->attachments);
            $this->authorizeExternalAttachments($manifest, $context);
            $this->validateNativeSettings($manifest, $context, true);
            $context->setNativeInput($manifest);
        }

        $invocation = $manifest?->invocationFor($recipient, $topologyText, $attempt) ?? new NativeAgentInvocation($topologyText);
        if ($invocation->conversation !== null) {
            $this->authorizeConversation($invocation->conversation, $context);
        }
        if ($invocation->prompt instanceof UserMessage) {
            $this->audit->emit('native_input.released', [
                'run_id' => $context->runId,
                'recipient' => $recipient,
                'attachment_count' => $invocation->prompt->attachments->count(),
                'provider_override' => $invocation->provider !== null,
                'model_override' => $invocation->model !== null,
                'timeout_override' => $invocation->timeout !== null,
                'tools_configured' => $invocation->toolsConfigured,
                'tool_count' => count($invocation->tools),
                'messages_configured' => $invocation->messagesConfigured,
                'message_count' => count($invocation->messages),
                'conversation_mode' => $invocation->conversation === null
                    ? 'none'
                    : ($invocation->conversation->conversationId === null ? 'start' : 'continue'),
            ]);
        }

        return $invocation;
    }

    public function commitConsumedMessages(RunContext $context, NativeAgentSettingsAttempt $attempt): void
    {
        $reference = $context->nativeInputReference();
        $ids = $attempt->ids();
        if ($reference === null || $ids === []) {
            return;
        }

        if (! $this->store instanceof ConsumesNativeInputMessages) {
            throw new SwarmException('The configured native input store cannot commit one-shot withMessages state. Use the database native input store for recoverable execution.');
        }

        $this->store->consumeMessages($reference, $context->runId, $ids);
    }

    public function commitTerminal(
        RunContext $context,
        NativeAgentSettingsAttempt $attempt,
        Closure $terminalWrite,
    ): mixed {
        $reference = $context->nativeInputReference();
        $ids = $attempt->ids();
        if ($reference === null || $ids === []) {
            return $terminalWrite();
        }

        if ($this->config->get('swarm.history.driver') !== 'database') {
            throw new SwarmException('Recoverable withMessages requires [swarm.history.driver] to be [database] so terminal history and one-shot consumption commit atomically.');
        }
        $store = $this->store;
        if (! $store instanceof ConsumesNativeInputMessages) {
            throw new SwarmException('The configured native input store cannot atomically commit terminal history and one-shot withMessages state. Implement ConsumesNativeInputMessages or use the database native input store.');
        }

        return $store->transaction(function () use ($terminalWrite, $reference, $context, $ids, $store): mixed {
            $result = $terminalWrite();
            $store->consumeMessages($reference, $context->runId, $ids);

            return $result;
        });
    }

    /**
     * @param  list<File>  $attachments
     * @return array{list<File>, array<int, string>, list<int>, list<array{disk: string, path: string, content: string, options: array<string, string>}>}
     */
    protected function planRecoverable(array $attachments, string $runId): array
    {
        if ($attachments === []) {
            return [[], [], [], []];
        }

        $diskName = $this->config->get('swarm.native_inputs.disk');
        $maxBytes = $this->maxAttachmentBytes();

        $recoverable = [];
        $hashes = [];
        $owned = [];
        $promotions = [];
        foreach ($attachments as $index => $attachment) {
            if (! $attachment instanceof Arrayable) {
                throw new SwarmException('Native attachments must provide Laravel AI array serialization.');
            }

            $descriptor = $attachment->toArray();
            $type = (string) ($descriptor['type'] ?? '');

            if (str_starts_with($type, 'provider-')) {
                $recoverable[] = $attachment;

                continue;
            }

            if (str_starts_with($type, 'stored-')) {
                if (! is_string($diskName) || $diskName === '' || ($descriptor['disk'] ?? null) !== $diskName) {
                    throw new SwarmException('Recoverable native attachments must use the explicitly configured [swarm.native_inputs.disk].');
                }
                if ($attachment instanceof StorableFile) {
                    $size = $this->filesystems->disk($diskName)->size((string) ($descriptor['path'] ?? ''));
                    if ($size > $maxBytes) {
                        throw new SwarmException("Native attachment [{$index}] exceeds the configured [{$maxBytes}] byte limit.");
                    }
                    $hashes[count($recoverable)] = hash('sha256', $attachment->content());
                }
                $recoverable[] = $attachment;

                continue;
            }

            if (str_starts_with($type, 'remote-')) {
                throw new SwarmException('Remote native attachments are request-local only; recoverable dispatch does not fetch arbitrary remote URLs. Store the file on the configured private disk first.');
            }

            if (! is_string($diskName) || $diskName === '') {
                throw new SwarmException('Inline and local native attachments require [swarm.native_inputs.disk] so Swarm can promote them before background or cross-process execution.');
            }

            if (! $attachment instanceof StorableFile) {
                throw new SwarmException("Native attachment type [{$type}] cannot be promoted for recoverable execution.");
            }

            $content = $attachment->content();
            if (strlen($content) > $maxBytes) {
                throw new SwarmException("Native attachment [{$index}] exceeds the configured [{$maxBytes}] byte limit.");
            }

            $name = $attachment->name();
            $mime = $attachment->mimeType();
            $extension = is_string($name) && pathinfo($name, PATHINFO_EXTENSION) !== ''
                ? '.'.preg_replace('/[^A-Za-z0-9]+/', '', pathinfo($name, PATHINFO_EXTENSION))
                : '';
            $path = 'swarm/native-inputs/'.$runId.'/'.Str::uuid().$extension;
            $options = ['visibility' => 'private'];
            if (is_string($mime) && $mime !== '') {
                $options['mimetype'] = $mime;
            }
            $promotions[] = ['disk' => $diskName, 'path' => $path, 'content' => $content, 'options' => $options];

            $stored = match (true) {
                str_ends_with($type, '-image') => new StoredImage($path, $diskName),
                str_ends_with($type, '-document') => new StoredDocument($path, $diskName),
                str_ends_with($type, '-audio') => new StoredAudio($path, $diskName),
                str_ends_with($type, '-video') => new StoredVideo($path, $diskName),
                default => throw new SwarmException("Native attachment type [{$type}] is not supported for recoverable execution."),
            };
            $stored->as($name);
            if (is_string($mime) && $mime !== '') {
                $stored->withMimeType($mime);
            }
            $recoverable[] = $stored;
            $hashes[count($recoverable) - 1] = hash('sha256', $content);
            $owned[] = count($recoverable) - 1;
        }

        return [$recoverable, $hashes, $owned, $promotions];
    }

    /**
     * Promote user-message attachments through the same sealed, private-disk
     * path as top-level native input attachments.
     *
     * @return list<array{disk: string, path: string, content: string, options: array<string, string>}>
     */
    protected function planRecoverableMessages(NativeInputManifest $manifest, string $runId): array
    {
        $promotions = [];

        foreach ($manifest->recipients as $recipientIndex => $recipient) {
            $messages = [];
            $metadata = [];
            foreach ($recipient->messages as $messageIndex => $message) {
                if (! $message instanceof UserMessage || $message->attachments->isEmpty()) {
                    $messages[] = $message;

                    continue;
                }

                $originalAttachments = $this->messageAttachments($message);
                foreach ($originalAttachments as $attachment) {
                    NativeInputManifest::assertMessageAttachmentIsReconstructible($attachment);
                }
                [$attachments, $hashes, $owned, $planned] = $this->planRecoverable(
                    $originalAttachments,
                    $runId,
                );
                $messages[] = new UserMessage($message->content, $attachments);
                foreach ($attachments as $attachmentIndex => $_attachment) {
                    $row = [];
                    if (is_string($hashes[$attachmentIndex] ?? null)) {
                        $row['sha256'] = $hashes[$attachmentIndex];
                    }
                    if (in_array($attachmentIndex, $owned, true)) {
                        $row['owned'] = true;
                    }
                    $mime = $originalAttachments[$attachmentIndex]->mimeType();
                    if (is_string($mime) && $mime !== '') {
                        $row['mime'] = $mime;
                    }
                    if ($row !== []) {
                        $metadata[$messageIndex][$attachmentIndex] = $row;
                    }
                }
                $promotions = array_merge($promotions, $planned);
            }

            $manifest->recipients[$recipientIndex] = $recipient->withResolvedSettings(
                $recipient->tools,
                messages: $messages,
                messageAttachmentMetadata: $metadata,
            );
        }

        return $promotions;
    }

    /** @param list<File> $attachments */
    protected function validateAttachmentLimits(array $attachments): void
    {
        $maxCount = max(1, (int) $this->config->get('swarm.native_inputs.max_attachments', 8));
        if (count($attachments) > $maxCount) {
            throw new SwarmException("Native swarm input accepts at most [{$maxCount}] attachments per run.");
        }

        $diskName = $this->config->get('swarm.native_inputs.disk');
        foreach ($attachments as $index => $attachment) {
            if (! $attachment instanceof Arrayable) {
                throw new SwarmException('Native attachments must provide Laravel AI array serialization.');
            }

            $descriptor = $attachment->toArray();
            $type = (string) ($descriptor['type'] ?? '');
            if (str_starts_with($type, 'remote-') || str_starts_with($type, 'provider-')) {
                continue;
            }

            if (str_starts_with($type, 'stored-')
                && is_string($diskName) && ($descriptor['disk'] ?? null) === $diskName) {
                $size = $this->filesystems->disk($diskName)->size((string) ($descriptor['path'] ?? ''));
                if ($size > $this->maxAttachmentBytes()) {
                    throw new SwarmException("Native attachment [{$index}] exceeds the configured [{$this->maxAttachmentBytes()}] byte limit.");
                }
            } elseif ($attachment instanceof StorableFile && strlen($attachment->content()) > $this->maxAttachmentBytes()) {
                throw new SwarmException("Native attachment [{$index}] exceeds the configured [{$this->maxAttachmentBytes()}] byte limit.");
            }
        }
    }

    /** @param list<File> $attachments */
    protected function assertRecoverableSources(array $attachments): void
    {
        $disk = $this->config->get('swarm.native_inputs.disk');
        foreach ($attachments as $attachment) {
            if (! $attachment instanceof Arrayable) {
                continue;
            }

            $descriptor = $attachment->toArray();
            $type = (string) ($descriptor['type'] ?? '');
            if (str_starts_with($type, 'remote-')) {
                throw new SwarmException('Remote native attachments are request-local only; recoverable dispatch does not fetch arbitrary remote URLs. Store the file on the configured private disk first.');
            }

            if (str_starts_with($type, 'stored-')
                && (! is_string($disk) || $disk === '' || ($descriptor['disk'] ?? null) !== $disk)) {
                throw new SwarmException('Recoverable native attachments must use the explicitly configured [swarm.native_inputs.disk].');
            }
        }
    }

    protected function maxAttachmentBytes(): int
    {
        return max(1, (int) $this->config->get('swarm.native_inputs.max_attachment_bytes', 10485760));
    }

    protected function authorizeExternalAttachments(NativeInputManifest $manifest, RunContext $context): void
    {
        foreach ($manifest->attachments as $index => $attachment) {
            if (in_array($index, $manifest->ownedAttachmentIndexes, true)) {
                continue;
            }

            if (! $attachment instanceof Arrayable) {
                continue;
            }

            $type = (string) ($attachment->toArray()['type'] ?? '');
            if ((str_starts_with($type, 'stored-') || str_starts_with($type, 'provider-'))
                && ! $this->authorizer->authorize($attachment, $context)) {
                throw new SwarmException("Native attachment [{$index}] is not authorized for this actor or tenant.");
            }
        }
    }

    /** @param array<string, mixed> $payload */
    protected function validateRecoveredDescriptors(array $payload, string $runId): void
    {
        $disk = $this->config->get('swarm.native_inputs.disk');
        $descriptors = $payload['attachments'] ?? [];
        foreach ($payload['recipients'] ?? [] as $recipient) {
            if (! is_array($recipient)) {
                continue;
            }
            foreach ($recipient['messages'] ?? [] as $message) {
                if (is_array($message) && ($message['type'] ?? null) === 'user' && is_array($message['attachments'] ?? null)) {
                    $descriptors = array_merge($descriptors, $message['attachments']);
                }
            }
        }

        foreach ($descriptors as $index => $attachment) {
            if (! is_array($attachment)) {
                throw new SwarmException("Native attachment descriptor [{$index}] is invalid.");
            }

            $type = (string) ($attachment['type'] ?? '');
            if (! str_starts_with($type, 'stored-') && ! str_starts_with($type, 'provider-')) {
                throw new SwarmException("Native attachment descriptor [{$index}] is not recoverable.");
            }

            if (str_starts_with($type, 'stored-')) {
                $path = $attachment['path'] ?? null;
                if (! is_string($disk) || $disk === '' || ($attachment['disk'] ?? null) !== $disk || ! is_string($path) || $path === '') {
                    throw new SwarmException("Native attachment descriptor [{$index}] does not use the configured private disk.");
                }
                if (($attachment['swarm_owned'] ?? false) === true
                    && ! str_starts_with($path, 'swarm/native-inputs/'.$runId.'/')) {
                    throw new SwarmException("Native attachment descriptor [{$index}] escaped its run-owned path.");
                }
            } elseif (! is_string($attachment['id'] ?? null) || $attachment['id'] === '') {
                throw new SwarmException("Native provider attachment descriptor [{$index}] is invalid.");
            }

            if (isset($attachment['swarm_content_sha256'])
                && (! is_string($attachment['swarm_content_sha256']) || preg_match('/\A[a-f0-9]{64}\z/', $attachment['swarm_content_sha256']) !== 1)) {
                throw new SwarmException("Native attachment descriptor [{$index}] has an invalid content identity.");
            }
        }
    }

    /** @param list<array{disk: string, path: string, content: string, options: array<string, string>}> $promotions */
    protected function promote(array $promotions): void
    {
        foreach ($promotions as $index => $promotion) {
            if (! $this->filesystems->disk($promotion['disk'])->put($promotion['path'], $promotion['content'], $promotion['options'])) {
                throw new SwarmException("Native attachment [{$index}] could not be promoted to the configured private disk.");
            }
        }
    }

    /** @param list<array{disk: string, path: string}> $locations */
    protected function deleteOwnedLocations(array $locations): void
    {
        foreach (array_reverse($locations) as $location) {
            try {
                $disk = $this->filesystems->disk($location['disk']);
                if ($disk->exists($location['path'])) {
                    $disk->delete($location['path']);
                }
            } catch (Throwable) {
                // Best effort. The revoked envelope is retained as the
                // authoritative prune locator for any bytes that remain.
            }
        }
    }

    public function abandon(RunContext $context): void
    {
        $reference = $context->nativeInputReference();
        if ($reference === null) {
            return;
        }

        try {
            $row = $this->store->find($reference);
            if ($row === null || $row['run_id'] !== $context->runId) {
                return;
            }

            $this->store->revoke($reference, $context->runId);
            $locations = [];
            $attachments = $row['payload']['attachments'] ?? [];
            foreach ($row['payload']['recipients'] ?? [] as $recipient) {
                if (! is_array($recipient)) {
                    continue;
                }
                foreach ($recipient['messages'] ?? [] as $message) {
                    if (is_array($message) && ($message['type'] ?? null) === 'user' && is_array($message['attachments'] ?? null)) {
                        $attachments = array_merge($attachments, $message['attachments']);
                    }
                }
            }
            foreach ($attachments as $attachment) {
                if (! is_array($attachment) || ($attachment['swarm_owned'] ?? false) !== true) {
                    continue;
                }

                $disk = $attachment['disk'] ?? null;
                $path = $attachment['path'] ?? null;
                if (is_string($disk) && is_string($path)
                    && $disk === $this->config->get('swarm.native_inputs.disk')
                    && str_starts_with($path, 'swarm/native-inputs/'.$context->runId.'/')) {
                    $locations[] = ['disk' => $disk, 'path' => $path];
                }
            }
            $this->deleteOwnedLocations($locations);
        } catch (Throwable) {
            // Pre-dispatch cleanup must never replace the original failure.
        }
    }

    protected function applyDefaultRecipient(NativeInputManifest $manifest, Topology $topology): void
    {
        if ($manifest->recipients !== []) {
            return;
        }

        if ($topology === Topology::Sequential) {
            $manifest->recipients = [NativeInputRecipient::sequential(0, 'original')];
        } elseif ($topology === Topology::Hierarchical) {
            $manifest->recipients = [NativeInputRecipient::generatedCoordinator('original')];
        } elseif ($manifest->attachments !== []) {
            throw new SwarmException('Parallel and static-hierarchical native attachments require explicit slot or node recipients; Swarm never fans sensitive files out implicitly.');
        }
    }

    protected function validateRecipients(NativeInputManifest $manifest, Topology $topology): void
    {
        $prefix = match ($topology) {
            Topology::Sequential => 'sequential:',
            Topology::Parallel => 'parallel:',
            Topology::Hierarchical => 'generated:',
            Topology::StaticHierarchical => 'static:',
        };

        $seen = [];
        foreach ($manifest->recipients as $recipient) {
            if (! str_starts_with($recipient->recipient, $prefix)) {
                throw new SwarmException("Native input recipient [{$recipient->recipient}] does not belong to the [{$topology->value}] topology.");
            }

            if (isset($seen[$recipient->recipient])) {
                throw new SwarmException("Native input recipient [{$recipient->recipient}] is declared more than once.");
            }
            $seen[$recipient->recipient] = true;

            foreach ($recipient->attachments ?? [] as $index) {
                if (! array_key_exists($index, $manifest->attachments)) {
                    throw new SwarmException("Native input recipient [{$recipient->recipient}] selects missing attachment [{$index}].");
                }
            }
        }

        $configurationIds = [];
        foreach ($manifest->recipients as $recipient) {
            if (! $recipient->hasNativeSettings()) {
                continue;
            }

            $id = $recipient->settingsId();
            if (isset($configurationIds[$id])) {
                throw new SwarmException("Native agent configuration ID [{$id}] is declared more than once.");
            }
            $configurationIds[$id] = true;
        }

        foreach ($manifest->consumedMessageConfigurationIds as $id) {
            if (! isset($configurationIds[$id])) {
                throw new SwarmException("Native input envelope contains unknown consumed message configuration ID [{$id}].");
            }
        }
    }

    protected function hasSettings(NativeInputManifest $manifest): bool
    {
        return array_filter(
            $manifest->recipients,
            static fn (NativeInputRecipient $recipient): bool => $recipient->hasNativeSettings(),
        ) !== [];
    }

    protected function freezeToolFactories(NativeInputManifest $manifest): void
    {
        foreach ($manifest->recipients as $index => $recipient) {
            $references = [];
            foreach ($recipient->tools as $tool) {
                if ($tool instanceof NativeAgentToolReference) {
                    $references[] = $tool;

                    continue;
                }

                if (! $tool instanceof NativeAgentToolFactoryReference) {
                    throw new SwarmException('Native agent tools must use reconstructible references or registered factories.');
                }

                $factories = $this->config->get('swarm.native_agent_settings.tool_factories', []);
                $factoryClass = is_array($factories) ? ($factories[$tool->factory] ?? null) : null;
                if (! is_string($factoryClass) || $factoryClass === '') {
                    throw new SwarmException("Native agent tool factory [{$tool->factory}] is not registered in [swarm.native_agent_settings.tool_factories].");
                }

                $factory = $this->container->make($factoryClass);
                if (! $factory instanceof NativeAgentToolFactory) {
                    throw new SwarmException("Native agent tool factory [{$tool->factory}] must implement NativeAgentToolFactory.");
                }

                foreach ($factory->references($tool->arguments) as $reference) {
                    if (! $reference instanceof NativeAgentToolReference) {
                        throw new SwarmException("Native agent tool factory [{$tool->factory}] must return only NativeAgentToolReference values.");
                    }
                    $references[] = $reference;
                }
            }

            $manifest->recipients[$index] = $recipient->withResolvedSettings($references);
        }
    }

    protected function validateNativeSettings(NativeInputManifest $manifest, RunContext $context, bool $recoverable): void
    {
        foreach ($manifest->recipients as $recipient) {
            foreach ($recipient->tools as $reference) {
                if (! $reference instanceof NativeAgentToolReference) {
                    throw new SwarmException("Native agent configuration [{$recipient->settingsId()}] contains a tool class that cannot be reconstructed.");
                }
                NativeAgentToolResolver::resolve($reference, $this->container);
            }

            foreach ($recipient->messages as $messageIndex => $message) {
                NativeMessageCodec::encode($message);
                if ($message instanceof UserMessage) {
                    $attachments = $this->messageAttachments($message);
                    $this->validateAttachmentLimits($attachments);
                    if ($recoverable) {
                        $this->assertRecoverableSources($attachments);
                    }

                    foreach ($attachments as $attachmentIndex => $attachment) {
                        if ($recipient->ownsMessageAttachment($messageIndex, $attachmentIndex)
                            || ! $attachment instanceof Arrayable) {
                            continue;
                        }
                        $type = (string) ($attachment->toArray()['type'] ?? '');
                        if ((str_starts_with($type, 'stored-') || str_starts_with($type, 'provider-'))
                            && ! $this->authorizer->authorize($attachment, $context)) {
                            throw new SwarmException("Native withMessages attachment [{$attachmentIndex}] is not authorized for this actor or tenant.");
                        }
                    }
                }
            }

            if ($recipient->conversation === null) {
                continue;
            }

            if ($recoverable && $recipient->conversation->conversationId === null) {
                throw new SwarmException("Native agent configuration [{$recipient->settingsId()}] starts a new Laravel AI conversation, whose generated ID cannot be reconstructed after retry. Background, process, queued, durable, and routed execution require an existing conversation ID; create or obtain it in the application first.");
            }
            if ($recoverable) {
                $recipient->conversation->toArray();
            }

            $this->authorizeConversation($recipient->conversation, $context);
        }
    }

    protected function authorizeConversation(NativeAgentConversation $conversation, RunContext $context): void
    {
        $participant = $conversation->participant();
        $conversationAuthorizer = $this->container->make(AuthorizesNativeAgentConversation::class);
        if (! $conversationAuthorizer->authorize($conversation->conversationId, $participant, $context)) {
            throw new SwarmException('Native Laravel AI conversation access is not authorized for this actor or tenant. Bind AuthorizesNativeAgentConversation to an application policy.');
        }

        if ($conversation->conversationId === null) {
            return;
        }

        $store = $this->container->bound(VerifiesConversationOwnership::class)
            ? $this->container->make(VerifiesConversationOwnership::class)
            : $this->container->make(ConversationStore::class);
        if (! $store instanceof VerifiesConversationOwnership) {
            throw new SwarmException('Native conversation continuation requires Laravel AI conversation storage that implements VerifiesConversationOwnership.');
        }
        if (! $store->conversationBelongsTo(
            $conversation->conversationId,
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
        )) {
            throw new SwarmException('The native Laravel AI conversation does not belong to the declared participant.');
        }
    }

    /** @return list<File> */
    protected function messageAttachments(UserMessage $message): array
    {
        $attachments = [];
        foreach ($message->attachments as $attachment) {
            if (! $attachment instanceof File) {
                throw new SwarmException('Native withMessages user attachments must be reconstructible Laravel AI files.');
            }
            $attachments[] = $attachment;
        }

        return $attachments;
    }

    protected function requiresOperationalReference(NativeInputManifest $manifest, Topology $topology, ExecutionMode $mode): bool
    {
        if (in_array($mode, [ExecutionMode::Queue, ExecutionMode::Durable], true) || $topology === Topology::Parallel || $topology === Topology::StaticHierarchical) {
            return true;
        }

        if ($topology === Topology::Sequential) {
            return false;
        }

        return array_filter(
            $manifest->recipients,
            static fn (NativeInputRecipient $recipient): bool => $recipient->recipient !== 'sequential:0'
                && $recipient->recipient !== 'generated:coordinator',
        ) !== [];
    }

    protected function hasConsumableMessages(NativeInputManifest $manifest): bool
    {
        return array_filter(
            $manifest->recipients,
            static fn (NativeInputRecipient $recipient): bool => $recipient->messages !== [],
        ) !== [];
    }

    /** @param array<string, mixed> $payload */
    protected function authorizeRecoveredMessageAttachments(array $payload, RunContext $context): void
    {
        foreach ($payload['recipients'] ?? [] as $recipient) {
            if (! is_array($recipient)) {
                continue;
            }
            foreach ($recipient['messages'] ?? [] as $message) {
                if (! is_array($message) || ($message['type'] ?? null) !== 'user') {
                    continue;
                }
                foreach ($message['attachments'] ?? [] as $index => $descriptor) {
                    if (! is_array($descriptor) || ($descriptor['swarm_owned'] ?? false) === true) {
                        continue;
                    }
                    $type = (string) ($descriptor['type'] ?? '');
                    if (! str_starts_with($type, 'stored-') && ! str_starts_with($type, 'provider-')) {
                        continue;
                    }
                    $attachment = File::fromArray($descriptor);
                    if (! $attachment instanceof File || ! $this->authorizer->authorize($attachment, $context)) {
                        throw new SwarmException("Native withMessages attachment [{$index}] is not authorized for this actor or tenant.");
                    }
                }
            }
        }
    }
}
