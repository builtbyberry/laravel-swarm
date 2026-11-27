<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\JoinOpenWait;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Hooks around the owning {@see DatabaseDurableRunStore} methods:
 *
 * - beforeSync / afterSync: immediately before / after parent::syncRunWaitSummary;
 * - beforeRunLock: once, immediately before the first parent::lockRunRow call after it
 *   is set, then cleared;
 * - afterRunLock: after parent::lockRunRow returns;
 * - afterJoinCheck: after parent::releaseWaitingRunForJoin returns, inside this
 *   fixture's surrounding transaction.
 */
final class HookedJoinDurableRunStore extends DatabaseDurableRunStore
{
    public ?Closure $beforeSync = null;

    public ?Closure $afterSync = null;

    public ?Closure $afterJoinCheck = null;

    public ?Closure $afterRunLock = null;

    public ?Closure $beforeRunLock = null;

    public int $runLockAttempts = 0;

    protected function lockRunRow(string $runId): ?object
    {
        $this->runLockAttempts++;

        if ($this->beforeRunLock !== null) {
            $hook = $this->beforeRunLock;
            $this->beforeRunLock = null;
            $hook($runId);
        }

        $run = parent::lockRunRow($runId);
        if ($this->afterRunLock !== null) {
            ($this->afterRunLock)($runId);
        }

        return $run;
    }

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
