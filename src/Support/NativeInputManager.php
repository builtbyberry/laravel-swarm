<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

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

final class NativeInputManager
{
    public function __construct(
        protected ConfigRepository $config,
        protected NativeInputStore $store,
        protected FilesystemFactory $filesystems,
    ) {}

    public function admit(RunContext $context, Topology $topology, ExecutionMode $mode): void
    {
        $manifest = $context->nativeInput();
        if ($manifest === null && $context->nativeInputReference() !== null) {
            $this->invocation($context, '__load__', $context->input);
            $manifest = $context->nativeInput();
            if ($manifest !== null) {
                $context->input = $manifest->text;
            }
        }

        if ($manifest === null) {
            return;
        }

        if (! (bool) $this->config->get('swarm.native_inputs.enabled', false)) {
            throw new SwarmException('Native swarm input is disabled. Enable [swarm.native_inputs.enabled] only after the v1 readers and migration are deployed to every worker.');
        }

        $this->applyDefaultRecipient($manifest, $topology);
        $this->validateRecipients($manifest, $topology);

        if (! $this->requiresOperationalReference($manifest, $topology, $mode)) {
            return;
        }

        if ($context->nativeInputReference() !== null) {
            return;
        }

        if ($this->config->get('swarm.persistence.driver') !== 'database'
            || ! (bool) $this->config->get('swarm.persistence.encrypt_at_rest', false)) {
            throw new SwarmException('Recoverable native swarm input requires database persistence with [swarm.persistence.encrypt_at_rest] enabled.');
        }

        [$manifest->attachments, $manifest->attachmentHashes, $manifest->ownedAttachmentIndexes] = $this->makeRecoverable($manifest->attachments, $context->runId);
        $payload = $manifest->toArray();
        $payload['authorization'] = [
            'actor' => $context->metadata['actor'] ?? null,
            'tenant_id' => $context->metadata['tenant_id'] ?? null,
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $id = (string) Str::uuid();
        $expiresAt = time() + max(60, (int) $this->config->get('swarm.native_inputs.retention_seconds', 86400));

        $this->store->put($id, $context->runId, $payload, $hash, $expiresAt);
        $context->setNativeInputReference($id);
        $this->store->activate($id, $context->runId);
    }

    public function message(RunContext $context, string $recipient, string $topologyText): string|UserMessage
    {
        return $this->invocation($context, $recipient, $topologyText)->prompt;
    }

    public function invocation(RunContext $context, string $recipient, string $topologyText): NativeAgentInvocation
    {
        $manifest = $context->nativeInput();

        if ($manifest === null && ($reference = $context->nativeInputReference()) !== null) {
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

            $actualHash = hash('sha256', json_encode($row['payload'], JSON_THROW_ON_ERROR));
            if (! hash_equals($row['hash'], $actualHash)) {
                throw new SwarmException("Native input envelope [{$reference}] failed its content identity check.");
            }

            $manifest = NativeInputManifest::fromArray($row['payload']);
            $context->setNativeInput($manifest);
        }

        return $manifest?->invocationFor($recipient, $topologyText) ?? new NativeAgentInvocation($topologyText);
    }

    /**
     * @param  list<File>  $attachments
     * @return array{list<File>, array<int, string>, list<int>}
     */
    protected function makeRecoverable(array $attachments, string $runId): array
    {
        if ($attachments === []) {
            return [[], [], []];
        }

        $diskName = $this->config->get('swarm.native_inputs.disk');
        $maxBytes = max(1, (int) $this->config->get('swarm.native_inputs.max_attachment_bytes', 10485760));
        $maxCount = max(1, (int) $this->config->get('swarm.native_inputs.max_attachments', 8));

        if (count($attachments) > $maxCount) {
            throw new SwarmException("Native swarm input accepts at most [{$maxCount}] attachments per run.");
        }

        $recoverable = [];
        $hashes = [];
        $owned = [];
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
                $recoverable[] = $attachment;
                if ($attachment instanceof StorableFile) {
                    $hashes[count($recoverable) - 1] = hash('sha256', $attachment->content());
                }

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

            $path = 'swarm/native-inputs/'.$runId.'/'.Str::uuid();
            if (! $this->filesystems->disk($diskName)->put($path, $content)) {
                throw new SwarmException("Native attachment [{$index}] could not be promoted to the configured private disk.");
            }

            $recoverable[] = match (true) {
                str_ends_with($type, '-image') => new StoredImage($path, $diskName),
                str_ends_with($type, '-document') => new StoredDocument($path, $diskName),
                str_ends_with($type, '-audio') => new StoredAudio($path, $diskName),
                str_ends_with($type, '-video') => new StoredVideo($path, $diskName),
                default => throw new SwarmException("Native attachment type [{$type}] is not supported for recoverable execution."),
            };
            $hashes[count($recoverable) - 1] = hash('sha256', $content);
            $owned[] = count($recoverable) - 1;
        }

        return [$recoverable, $hashes, $owned];
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
