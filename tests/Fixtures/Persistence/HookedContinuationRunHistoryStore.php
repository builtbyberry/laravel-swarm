<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\QueuedRunAcquisition;
use Closure;

/**
 * The database history store with a one-shot hook that runs just before the
 * queued-hierarchical continuation lease is acquired, outside any transaction:
 * the resume job has already done its pre-lease reads, so the hook can move the
 * run on (a signal, a join release, another worker's continuation) in between.
 */
final class HookedContinuationRunHistoryStore extends DatabaseRunHistoryStore
{
    public ?Closure $beforeContinuationLease = null;

    public function acquireQueuedRunContinuationLease(string $runId, int $ttlSeconds, int $leaseSeconds): QueuedRunAcquisition
    {
        if ($this->beforeContinuationLease !== null) {
            $hook = $this->beforeContinuationLease;
            $this->beforeContinuationLease = null;
            $hook($runId);
        }

        return parent::acquireQueuedRunContinuationLease($runId, $ttlSeconds, $leaseSeconds);
    }
}
