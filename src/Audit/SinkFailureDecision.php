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
 * Queue       — persist the failed record to the audit outbox for later
 *               re-emission. The swarm:relay --type=audit lane drains the
 *               outbox by replaying records through the bound sink, deleting
 *               on success and incrementing the attempt count on transient
 *               failure. After swarm.audit.outbox.max_attempts (default 5)
 *               the record moves to the dead-letter status. When the audit
 *               outbox is unavailable (cache persistence driver, missing
 *               migration), the dispatcher degrades to log-and-swallow.
 * DeadLetter  — persist the failed record directly to the dead-letter
 *               status with no retry. Useful for categories that should
 *               never be re-emitted (e.g. a sink that explicitly rejected
 *               the payload as malformed).
 */
enum SinkFailureDecision
{
    case Swallow;
    case RetryInline;
    case Halt;
    case Queue;
    case DeadLetter;
}
