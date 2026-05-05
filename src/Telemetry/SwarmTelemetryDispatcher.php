<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Telemetry;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Routes normalized observability telemetry to the bound SwarmTelemetrySink.
 *
 * Enriches every payload with schema_version, category, and occurred_at before
 * forwarding to the sink. Sink exceptions are isolated per swarm.observability.failure_policy.
 */
class SwarmTelemetryDispatcher
{
    public function __construct(
        protected SwarmTelemetrySink $sink,
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $category, array $payload): void
    {
        if (! $this->isGloballyEnabled()) {
            return;
        }

        if (! $this->categoryAllowed($category)) {
            return;
        }

        $enriched = EvidenceEnvelope::enrich($category, $payload);

        try {
            $this->sink->emit($category, $enriched);
        } catch (Throwable $exception) {
            $this->handleSinkFailure($category, $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{metadata_keys: array<int, string>, metadata: array<string, mixed>}
     */
    public function metadata(array $metadata): array
    {
        return EvidenceEnvelope::metadata($metadata, $this->metadataAllowlist());
    }

    protected function isGloballyEnabled(): bool
    {
        return (bool) $this->config->get('swarm.observability.enabled', true);
    }

    protected function categoryAllowed(string $category): bool
    {
        $include = $this->config->get('swarm.observability.categories.include');
        $exclude = $this->config->get('swarm.observability.categories.exclude');

        if (is_array($include) && $include !== []) {
            return in_array($category, $include, true);
        }

        if (is_array($exclude) && $exclude !== []) {
            return ! in_array($category, $exclude, true);
        }

        return true;
    }

    protected function handleSinkFailure(string $category, Throwable $exception): void
    {
        $policy = (string) $this->config->get('swarm.observability.failure_policy', 'swallow');

        if ($policy === 'log') {
            $this->logger->error('Swarm telemetry sink failed.', [
                'category' => $category,
                'exception' => $exception->getMessage(),
                'class' => $exception::class,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function metadataAllowlist(): array
    {
        return EvidenceEnvelope::normalizeAllowlist(
            $this->config->get('swarm.observability.metadata_allowlist', []),
        );
    }
}
