<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\FanoutCloser;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Support\BranchWaitPayload;
use Closure;
use Illuminate\Support\Carbon;

/**
 * The database store with hooks at the lock points of a fan-out racing a wait closer:
 *
 * - beforeWaitForBranches: a fan-out caller is about to call waitForBranches(), inside
 *   its own transaction, with whatever it wrote before that still held;
 * - beforeBranchInsert: waitForBranches() has written the context row and is about to
 *   insert its branch rows;
 * - beforeRunLock: a wait closer is about to ask for the run row lock — the first time,
 *   whichever method takes it;
 * - beforeSync: a wait release has written its wait row and is entering
 *   syncRunWaitSummary().
 */
final class HookedFanoutCloserDurableRunStore extends DatabaseDurableRunStore
{
    public ?Closure $beforeWaitForBranches = null;

    public ?Closure $beforeBranchInsert = null;

    public ?Closure $beforeRunLock = null;

    public ?Closure $beforeSync = null;

    public function waitForBranches(string $runId, BranchWaitPayload $payload): void
    {
        if ($this->beforeWaitForBranches !== null) {
            ($this->beforeWaitForBranches)($runId);
        }

        parent::waitForBranches($runId, $payload);
    }

    protected function insertBranchRows(string $runId, array $branches, Carbon $timestamp, ?Carbon $expiresAt): void
    {
        if ($this->beforeBranchInsert !== null) {
            ($this->beforeBranchInsert)($runId);
        }

        parent::insertBranchRows($runId, $branches, $timestamp, $expiresAt);
    }

    protected function lockRunRow(string $runId): ?object
    {
        if ($this->beforeRunLock !== null) {
            ($this->beforeRunLock)($runId);
        }

        return parent::lockRunRow($runId);
    }

    protected function syncRunWaitSummary(string $runId, Carbon $timestamp): void
    {
        if ($this->beforeSync !== null) {
            ($this->beforeSync)($runId);
        }

        parent::syncRunWaitSummary($runId, $timestamp);
    }
}
