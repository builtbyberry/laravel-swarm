<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\PausedWait;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use Closure;

/**
 * One-shot test barrier after resume's shared wait scan and before its run lock.
 * Paused-signal contention tests synchronize here while the wait locks are held.
 */
class HookedResumeDurableRunStore extends DatabaseDurableRunStore
{
    public ?Closure $afterResumeWaitScan = null;

    protected function afterResumeWaitScan(string $runId): void
    {
        if ($this->afterResumeWaitScan !== null) {
            $hook = $this->afterResumeWaitScan;
            $this->afterResumeWaitScan = null;
            $hook($runId);
        }
    }
}
