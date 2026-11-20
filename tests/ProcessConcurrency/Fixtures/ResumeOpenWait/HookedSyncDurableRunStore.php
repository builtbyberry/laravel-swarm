<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use Closure;
use Illuminate\Support\Carbon;

/**
 * The database store with a hook between a wait release's wait-row UPDATE and its
 * syncRunWaitSummary() run-row UPDATE — the point at which a release holds the wait
 * row and has not yet asked for the run row.
 */
final class HookedSyncDurableRunStore extends DatabaseDurableRunStore
{
    public ?Closure $beforeSync = null;

    protected function syncRunWaitSummary(string $runId, Carbon $timestamp): void
    {
        if ($this->beforeSync !== null) {
            ($this->beforeSync)($runId);
        }

        parent::syncRunWaitSummary($runId, $timestamp);
    }
}
