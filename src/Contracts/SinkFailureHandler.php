<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use Throwable;

/**
 * Decides what to do when a SwarmAuditSink emit (or signing step) throws.
 *
 * Bind a custom implementation in the service container to route audit
 * failures into application-specific paths (retry, dead-letter, alerting,
 * tier-aware halting) without rewriting the dispatcher.
 *
 * The handler IS the loop control: as long as it returns RetryInline, the
 * dispatcher retries the same emit. The dispatcher's runaway guard
 * (MAX_HANDLER_ITERATIONS = 5) prevents infinite loops from buggy handlers
 * by throwing a runtime exception when exceeded.
 *
 * The default binding (ConfiguredSinkFailureHandler) maps the existing
 * swarm.audit.failure_policy config values:
 *   'swallow' → SinkFailureDecision::Swallow (no logging)
 *   'log'     → log via the application logger, then Swallow
 *   'halt'    → log via the application logger, then Halt (new in v0.4)
 *
 * Implementations may inspect $exception to differentiate sink failures
 * from signing failures (the SwarmAuditSigner failure path also routes
 * here, wrapped as the dispatcher sees fit).
 */
interface SinkFailureHandler
{
    /**
     * @param  array<string, mixed>  $payload  The enriched payload that failed to emit.
     */
    public function handle(
        SwarmAuditSink $sink,
        string $category,
        array $payload,
        Throwable $exception,
    ): SinkFailureDecision;
}
