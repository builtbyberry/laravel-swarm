<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\PausedSignal;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use Closure;

/**
 * The database store with a hook inside resume(), right after its shared locking scan of
 * the run's open waits: resume() calls find() as its next statement, so the hook runs
 * there while the scan's locks are held. Armed only for the duration of resume(), so the
 * lifecycle controller's own earlier read of the run does not trigger it, and it fires
 * once.
 */
class HookedResumeDurableRunStore extends DatabaseDurableRunStore
{
    public ?Closure $afterResumeWaitScan = null;

    private bool $inResume = false;

    public function resume(string $runId): bool
    {
        $this->inResume = true;

        try {
            return parent::resume($runId);
        } finally {
            $this->inResume = false;
        }
    }

    public function find(string $runId): ?array
    {
        if ($this->inResume && $this->afterResumeWaitScan !== null) {
            $hook = $this->afterResumeWaitScan;
            $this->afterResumeWaitScan = null;
            $hook($runId);
        }

        return parent::find($runId);
    }
}
