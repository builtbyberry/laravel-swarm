<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;

/**
 * The database context store as an application transaction on MySQL sees it: once a
 * run's snapshot is taken, find() keeps answering with the context as it was then,
 * the way an ordinary read under REPEATABLE READ does. findForUpdate(), a locking
 * read, still returns the current row. SQLite cannot hold an older snapshot across
 * another writer's commit, so this stands one in. The real engines are covered in
 * tests/ProcessConcurrency/DurableJoinOpenWaitTest.php.
 */
final class StaleSnapshotContextStore extends DatabaseContextStore
{
    /** @var array<string, array<string, mixed>> */
    private array $snapshots = [];

    /** @var array<string, int> */
    public array $lockingReads = [];

    public function takeSnapshot(string $runId): void
    {
        $this->snapshots[$runId] = (array) parent::find($runId);
    }

    public function dropSnapshot(string $runId): void
    {
        unset($this->snapshots[$runId]);
    }

    public function find(string $runId): ?array
    {
        return $this->snapshots[$runId] ?? parent::find($runId);
    }

    public function findForUpdate(string $runId): ?array
    {
        $this->lockingReads[$runId] = ($this->lockingReads[$runId] ?? 0) + 1;

        return parent::findForUpdate($runId);
    }
}
