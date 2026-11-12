<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\PausedSignal;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use Closure;

/**
 * One-shot resume barrier. The find() fallback supports the pre-#621 store;
 * afterResumeWaitScan() supports its planned explicit synchronization seam.
 * Both share one gate so a resume attempt cannot stop twice.
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
        $this->fireResumeWaitScanHook($runId);

        return parent::find($runId);
    }

    protected function afterResumeWaitScan(string $runId): void
    {
        $this->fireResumeWaitScanHook($runId);
    }

    private function fireResumeWaitScanHook(string $runId): void
    {
        if ($this->inResume && $this->afterResumeWaitScan !== null) {
            $hook = $this->afterResumeWaitScan;
            $this->afterResumeWaitScan = null;
            $hook($runId);
        }
    }
}
