<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\PausedWait;

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\PausedWait\HookedResumeDurableRunStore;
use Closure;
use Illuminate\Support\Carbon;

final class HookedDurableRunStore extends HookedResumeDurableRunStore
{
    public ?Closure $beforeSync = null;

    public ?Closure $afterRunLock = null;

    protected function syncRunWaitSummary(string $runId, Carbon $timestamp): void
    {
        if ($this->beforeSync !== null) {
            $hook = $this->beforeSync;
            $this->beforeSync = null;
            $hook($runId);
        }

        parent::syncRunWaitSummary($runId, $timestamp);
    }

    protected function lockRunRow(string $runId): ?object
    {
        $run = parent::lockRunRow($runId);
        if ($this->afterRunLock !== null) {
            $hook = $this->afterRunLock;
            $this->afterRunLock = null;
            $hook($runId);
        }

        return $run;
    }
}
