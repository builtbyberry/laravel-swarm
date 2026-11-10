<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Optional immediate `fail_run` release for durable stores.
 *
 * A store with this capability lets a `fail_run` branch failure fail its parent at once,
 * even while the parent has an open wait. A store without it keeps the open-wait-checked
 * {@see DurableRunStore::releaseWaitingRunForJoin()} path, described in
 * {@see \BuiltByBerry\LaravelSwarm\Runners\Durable\DurableHierarchicalCoordinator::failWaitingParentFromBranches()}
 * and the upgrade guide. A decorating store keeps the capability only if it implements
 * this interface and forwards the call.
 */
interface ReleasesWaitingRunForFailure
{
    /**
     * Move the run from `waiting` at `$nextStepIndex`, with no `finished_at`, to `pending`,
     * in one atomic conditional write, so its failure can be recorded under a fresh lease.
     * It does not look at the run's waits: open or not, they do not stop it.
     *
     * Returns true only when this call moved the run; false when it was not `waiting` at
     * that step index, was finished, or another caller moved it first. It takes no
     * wait-row lock after it writes the run row, the order every wait release takes.
     */
    public function releaseWaitingRunForFailure(string $runId, int $nextStepIndex): bool;
}
