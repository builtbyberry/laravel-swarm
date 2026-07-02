<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\DurableCancelResult;
use BuiltByBerry\LaravelSwarm\Responses\DurablePauseResult;
use BuiltByBerry\LaravelSwarm\Responses\DurableResumeResult;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;

/**
 * The public operator control contract for durable swarm runs.
 *
 * Resolve it from the container — `app(SwarmOperator::class)` — to pause,
 * resume, cancel, signal, or recover durable runs from an operator console,
 * an HTTP controller, an approval workflow, or scheduled maintenance.
 *
 * This contract is CONTROL-ONLY. Operational reads (status, current step,
 * queue routing, labels) already flow through the public RunHistoryStore /
 * SwarmHistory path — do not look for them here.
 *
 * Every verb takes a bare `runId` string. The contract is deliberately
 * AUTHORIZATION-AGNOSTIC: it performs no permission checks. Authorizing that
 * the caller may control a given run is the consuming application's
 * responsibility (a policy, a gate, or middleware in front of the call).
 *
 * Verbs never silently no-op. An unknown or foreign `runId`, or a run in a
 * status the verb cannot act on, throws
 * {@see SwarmException} — they either
 * apply the transition (or a definite scheduled transition) and report which,
 * or they fail loud.
 */
interface SwarmOperator
{
    /**
     * Pause a durable run. If the run is idle at a checkpoint it pauses
     * immediately (status `paused`); if it is mid-step it is marked to pause at
     * its next safe boundary (status `pause_scheduled`). The result reports
     * which happened.
     *
     * @throws SwarmException when the run is unknown or cannot be paused from its current status.
     */
    public function pause(string $runId): DurablePauseResult;

    /**
     * Resume a paused run. Either re-dispatches the next step (status
     * `resumed`) or re-arms a waiting boundary the run was paused on (status
     * `waiting`, with `waitingBoundaryDispatched` set). The result reports
     * which happened.
     *
     * @throws SwarmException when the run is unknown or is not paused.
     */
    public function resume(string $runId): DurableResumeResult;

    /**
     * Cancel a durable run. If the run is idle at a checkpoint it cancels
     * immediately (status `cancelled`) and cascades to active children; if it
     * is mid-step it is marked to cancel at its next safe boundary (status
     * `cancel_scheduled`). The result reports which happened.
     *
     * @throws SwarmException when the run is unknown or is already terminal.
     */
    public function cancel(string $runId): DurableCancelResult;

    /**
     * Deliver a named signal to a durable run. Idempotent per
     * `idempotencyKey`: a duplicate delivery is recorded and reported as
     * `duplicate` rather than re-applied. The result reports whether the signal
     * was accepted (released a matching wait) and its recorded status.
     *
     * @throws SwarmException when the run is unknown.
     */
    public function signal(string $runId, string $name, mixed $payload = null, ?string $idempotencyKey = null): DurableSignalResult;

    /**
     * Redispatch recoverable durable runs — those overdue for a step, branch,
     * retry, wait release, or child reconciliation. Optionally scope to a
     * single `runId` or a `swarmClass`. Idempotent: recovery is lease-guarded,
     * so a double dispatch is safe.
     *
     * @return array<int, string> the run ids that were redispatched
     */
    public function recover(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array;
}
