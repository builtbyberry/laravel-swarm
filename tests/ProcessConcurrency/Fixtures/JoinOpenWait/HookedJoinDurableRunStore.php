<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\JoinOpenWait;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use Closure;
use Illuminate\Support\Carbon;

/**
 * The database store with hooks at the lock points of a join handoff:
 *
 * - beforeSync: a wait release has UPDATEd its wait row (holds it) and has not yet
 *   asked for the run row;
 * - afterSync: the wait release has also written the run row (holds both) and has
 *   not committed;
 * - afterJoinCheck: releaseWaitingRunForJoin() has taken its shared locks on the
 *   run's open waits (held until the surrounding transaction ends) and decided.
 */
final class HookedJoinDurableRunStore extends DatabaseDurableRunStore
{
    public ?Closure $beforeSync = null;

    public ?Closure $afterSync = null;

    public ?Closure $afterJoinCheck = null;

    protected function syncRunWaitSummary(string $runId, Carbon $timestamp): void
    {
        if ($this->beforeSync !== null) {
            ($this->beforeSync)($runId);
        }

        parent::syncRunWaitSummary($runId, $timestamp);

        if ($this->afterSync !== null) {
            ($this->afterSync)($runId);
        }
    }

    public function releaseWaitingRunForJoin(string $runId, int $nextStepIndex): bool
    {
        if ($this->afterJoinCheck === null) {
            return parent::releaseWaitingRunForJoin($runId, $nextStepIndex);
        }

        // A savepoint around the parent's own transaction: the shared locks it takes
        // stay held until the outermost transaction ends, so the hook runs while they
        // are still held.
        return $this->connection->transaction(function () use ($runId, $nextStepIndex): bool {
            $released = parent::releaseWaitingRunForJoin($runId, $nextStepIndex);
            ($this->afterJoinCheck)($runId, $released);

            return $released;
        });
    }
}
