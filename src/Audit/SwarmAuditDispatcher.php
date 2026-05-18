<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Exceptions\AuditSinkHaltedException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Telemetry\EvidenceEnvelope;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Routes normalized audit evidence to the bound SwarmAuditSink.
 *
 * Enriches every payload with schema_version, category, and occurred_at
 * before forwarding. Sink failures are routed to the bound SinkFailureHandler,
 * which decides whether to swallow, retry inline, or halt the run. The
 * default ConfiguredSinkFailureHandler maps the swarm.audit.failure_policy
 * config value to a decision.
 *
 * The dispatcher caps retry iterations at MAX_HANDLER_ITERATIONS (5) to
 * prevent runaway loops from buggy custom handlers. Exceeding the cap throws
 * a SwarmException carrying the original sink failure as $previous.
 */
class SwarmAuditDispatcher
{
    /**
     * @deprecated Use EvidenceEnvelope::SCHEMA_VERSION
     */
    public const SCHEMA_VERSION = EvidenceEnvelope::SCHEMA_VERSION;

    /**
     * Maximum number of sink emit attempts per evidence record. Exceeded only
     * when a SinkFailureHandler keeps returning SinkFailureDecision::RetryInline
     * past this threshold — a defensive guard against runaway custom handlers.
     */
    public const MAX_HANDLER_ITERATIONS = 5;

    public function __construct(
        protected SwarmAuditSink $sink,
        protected ConfigRepository $config,
        protected SinkFailureHandler $failureHandler,
        protected ?SwarmAuditSigner $signer = null,
    ) {}

    /**
     * Emit a single evidence record to the bound sink.
     *
     * @param  array<string, mixed>  $payload  Domain-specific correlation fields.
     *                                         schema_version, category, and occurred_at
     *                                         are merged automatically.
     *
     * @throws AuditSinkHaltedException When the SinkFailureHandler returns Halt.
     *                                  Carries HaltsSwarmExecution marker.
     */
    public function emit(string $category, array $payload): void
    {
        $enriched = EvidenceEnvelope::enrich($category, $payload);

        if ($this->signer !== null) {
            try {
                $enriched = $this->signer->sign($category, $enriched);
            } catch (Throwable $exception) {
                $decision = $this->failureHandler->handle($this->sink, $category, $enriched, $exception);

                if ($decision === SinkFailureDecision::Halt) {
                    throw new AuditSinkHaltedException($category, $exception);
                }

                // Swallow and RetryInline both stop the emit here — we cannot
                // retry signing the same payload from inside the dispatcher
                // without delegating that retry decision back to the signer
                // itself, which is out of scope for v0.4.
                return;
            }
        }

        $attempts = 0;

        while (true) {
            try {
                $this->sink->emit($category, $enriched);

                return;
            } catch (Throwable $exception) {
                $attempts++;

                if ($attempts > self::MAX_HANDLER_ITERATIONS) {
                    throw new SwarmException(
                        sprintf(
                            'Sink failure handler exceeded the maximum of %d iterations while emitting [%s].',
                            self::MAX_HANDLER_ITERATIONS,
                            $category,
                        ),
                        0,
                        $exception,
                    );
                }

                $decision = $this->failureHandler->handle($this->sink, $category, $enriched, $exception);

                switch ($decision) {
                    case SinkFailureDecision::Swallow:
                        return;
                    case SinkFailureDecision::RetryInline:
                        continue 2;
                    case SinkFailureDecision::Halt:
                        throw new AuditSinkHaltedException($category, $exception);
                }
            }
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
