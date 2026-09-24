<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\AuthorizesNativeInputAttachment;
use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Files\StoredVideo;
use Laravel\Ai\Messages\UserMessage;
use Throwable;

final class NativeInputManager
{
    public function __construct(
        protected ConfigRepository $config,
        protected NativeInputStore $store,
        protected FilesystemFactory $filesystems,
        protected AuthorizesNativeInputAttachment $authorizer,
        protected SwarmAuditDispatcher $audit,
    ) {}

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

        $this->applyDefaultRecipient($manifest, $topology);
        $this->validateRecipients($manifest, $topology);
        $this->validateAttachmentLimits($manifest->attachments);
        $requiresOperationalReference = $this->requiresOperationalReference($manifest, $topology, $mode);
        if ($requiresOperationalReference) {
            $this->assertRecoverableSources($manifest->attachments);
        }
        $this->authorizeExternalAttachments($manifest, $context);

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
        $payload = $manifest->toArray();
        $payload['authorization'] = [
            'actor' => $context->metadata['actor'] ?? null,
            'tenant_id' => $context->metadata['tenant_id'] ?? null,
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
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

    public function invocation(RunContext $context, string $recipient, string $topologyText): NativeAgentInvocation
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
            if ($version !== NativeInputManifest::VERSION) {
                throw new SwarmException("Native input envelope [{$reference}] has unsupported format version [{$version}]. Upgrade every worker before enabling new native input writers.");
            }

            $this->validateRecoveredDescriptors($row['payload'], $context->runId);
            $manifest = NativeInputManifest::fromArray($row['payload']);
            $this->validateAttachmentLimits($manifest->attachments);
            $this->authorizeExternalAttachments($manifest, $context);
            $context->setNativeInput($manifest);
        }

        $invocation = $manifest?->invocationFor($recipient, $topologyText) ?? new NativeAgentInvocation($topologyText);
        if ($invocation->prompt instanceof UserMessage) {
            $this->audit->emit('native_input.released', [
                'run_id' => $context->runId,
                'recipient' => $recipient,
                'attachment_count' => $invocation->prompt->attachments->count(),
                'provider_override' => $invocation->provider !== null,
                'model_override' => $invocation->model !== null,
                'timeout_override' => $invocation->timeout !== null,
            ]);
        }

        return $invocation;
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
        foreach ($payload['attachments'] ?? [] as $index => $attachment) {
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
            foreach ($row['payload']['attachments'] ?? [] as $attachment) {
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
}
