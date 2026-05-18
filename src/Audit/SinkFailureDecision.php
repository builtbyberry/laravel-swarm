<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

/**
 * The decision a SinkFailureHandler returns when a sink emit (or signing
 * step) fails.
 *
 * Swallow     — log nothing further from the dispatcher, continue execution.
 *               The default ConfiguredSinkFailureHandler returns Swallow for
 *               both the legacy 'swallow' and 'log' failure policies (the
 *               handler performs the logging itself for 'log').
 * RetryInline — try the same emit again synchronously. The dispatcher loops
 *               until the handler stops returning RetryInline or reaches
 *               SwarmAuditDispatcher::MAX_HANDLER_ITERATIONS (5), at which
 *               point a runaway-guard exception is thrown.
 * Halt        — throw an AuditSinkHaltedException, which carries the
 *               HaltsSwarmExecution marker. The runner detects the marker
 *               and surfaces it as a run-level failure. Use this when
 *               regulated workloads must hard-fail rather than emit
 *               unattributed or unsigned evidence.
 *
 * The Queue and DeadLetter cases land in v0.5 alongside the audit outbox
 * table and swarm:relay --type=audit lane. Adding enum cases later is
 * non-breaking.
 */
enum SinkFailureDecision
{
    case Swallow;
    case RetryInline;
    case Halt;
}
