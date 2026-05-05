<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Telemetry\EvidenceEnvelope;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Routes normalized audit evidence to the bound SwarmAuditSink.
 *
 * Enriches every payload with schema_version, category, and occurred_at before
 * forwarding to the sink. Sink exceptions are isolated and handled according to
 * swarm.audit.failure_policy — they never propagate into swarm execution.
 *
 * Supported failure policies:
 *   swallow — silently discard the exception (default, safest for production).
 *   log     — record the exception via the application logger, then continue.
 */
class SwarmAuditDispatcher
{
    /**
     * @deprecated Use EvidenceEnvelope::SCHEMA_VERSION
     */
    public const SCHEMA_VERSION = EvidenceEnvelope::SCHEMA_VERSION;

    public function __construct(
        protected SwarmAuditSink $sink,
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Emit a single evidence record to the bound sink.
     *
     * @param  array<string, mixed>  $payload  Domain-specific correlation fields.
     *                                         schema_version, category, and occurred_at
     *                                         are merged automatically.
     */
    public function emit(string $category, array $payload): void
    {
        $enriched = EvidenceEnvelope::enrich($category, $payload);

        try {
            $this->sink->emit($category, $enriched);
        } catch (Throwable $exception) {
            $this->handleSinkFailure($category, $exception);
        }
    }

    /**
     * Return default-safe metadata evidence for audit payloads.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{metadata_keys: array<int, string>, metadata: array<string, mixed>}
     */
    public function metadata(array $metadata): array
    {
        return EvidenceEnvelope::metadata($metadata, $this->metadataAllowlist());
    }

    protected function handleSinkFailure(string $category, Throwable $exception): void
    {
        $policy = (string) $this->config->get('swarm.audit.failure_policy', 'swallow');

        if ($policy === 'log') {
            $this->logger->error('Swarm audit sink failed.', [
                'category' => $category,
                'exception' => $exception->getMessage(),
                'class' => $exception::class,
            ]);
        }

        // Never rethrow — sink failures must not affect swarm execution.
    }

    /**
     * @return array<int, string>
     */
    protected function metadataAllowlist(): array
    {
        return EvidenceEnvelope::normalizeAllowlist(
            $this->config->get('swarm.audit.metadata_allowlist', []),
        );
    }
}
