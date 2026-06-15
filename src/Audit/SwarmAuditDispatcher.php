<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Exceptions\AuditSinkHaltedException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Telemetry\EvidenceEnvelope;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
 *
 * @internal
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
        protected ?AuditOutbox $outbox = null,
        protected ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger;
    }

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
                $this->assertSignedPayloadIsVerifiable($category, $enriched);
            } catch (Throwable $exception) {
                $decision = $this->failureHandler->handle($this->sink, $category, $enriched, $exception);

                if ($decision === SinkFailureDecision::Halt) {
                    throw new AuditSinkHaltedException($category, $exception);
                }

                if ($decision === SinkFailureDecision::Queue || $decision === SinkFailureDecision::DeadLetter) {
                    $this->routeToOutbox($category, $enriched, $decision === SinkFailureDecision::DeadLetter, $exception);

                    return;
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
                    case SinkFailureDecision::Queue:
                        $this->routeToOutbox($category, $enriched, false, $exception);

                        return;
                    case SinkFailureDecision::DeadLetter:
                        $this->routeToOutbox($category, $enriched, true, $exception);

                        return;
                }
            }
        }
    }

    /**
     * Enforce that a signed payload carries the algorithm name needed to verify
     * and rotate it.
     *
     * The package signs on emit but never verifies on read — that is the sink's
     * responsibility (see docs/audit-evidence-contract.md "Audit Signing"). A
     * signer that adds a `signature` but no `signature_algorithm` produces a
     * record that can never be re-verified after a key or algorithm change, so
     * we treat it as a signing failure and route it through the
     * SinkFailureHandler like any other signing failure. Under halt/swallow the
     * record never reaches the sink; under a queue/dead-letter policy it follows
     * the outbox path and is delivered on the next drain (the outbox replays the
     * stored payload directly and does not re-run this guard).
     *
     * An unsigned payload — the documented per-category opt-out, where the
     * signer returns the input unchanged — passes through untouched.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws SwarmException When a non-empty signature is present without a
     *                        non-empty signature_algorithm.
     */
    protected function assertSignedPayloadIsVerifiable(string $category, array $payload): void
    {
        $signature = $payload['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            // Unsigned, or the signer opted out of this category — nothing to enforce.
            return;
        }

        $algorithm = $payload['signature_algorithm'] ?? null;

        if (is_string($algorithm) && $algorithm !== '') {
            return;
        }

        throw new SwarmException(sprintf(
            'SwarmAuditSigner signed [%s] without a non-empty "signature_algorithm". '
            .'Verification and key rotation require the algorithm name; have your signer '
            .'add it alongside "signature" (see docs/audit-evidence-contract.md "Audit Signing").',
            $category,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function routeToOutbox(string $category, array $payload, bool $deadLetter, Throwable $cause): void
    {
        if ($this->outbox === null || ! $this->outbox->isAvailable()) {
            $this->logger->warning(
                'Swarm audit sink failed and outbox is unavailable; swallowing.',
                [
                    'category' => $category,
                    'decision' => $deadLetter ? 'dead_letter' : 'queue',
                    'exception' => $cause->getMessage(),
                    'class' => $cause::class,
                ],
            );

            return;
        }

        try {
            $this->outbox->enqueue($category, $payload, $deadLetter);
        } catch (Throwable $outboxException) {
            // Persisting to the outbox failed (DB outage, etc.). Swallow with
            // a log so the original sink failure is not masked by an outbox failure.
            $this->logger->error(
                'Swarm audit outbox enqueue failed; swallowing original sink failure.',
                [
                    'category' => $category,
                    'decision' => $deadLetter ? 'dead_letter' : 'queue',
                    'original_exception' => $cause->getMessage(),
                    'original_class' => $cause::class,
                    'outbox_exception' => $outboxException->getMessage(),
                    'outbox_class' => $outboxException::class,
                ],
            );
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
