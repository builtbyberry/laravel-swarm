<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Exceptions\SnapshotFrozenException;

/**
 * No-op {@see SnapshotsMemory} implementation used when persistence runs in
 * `cache` mode and the `swarm_memory_snapshots` table is not migrated.
 *
 * The runner contract is unchanged — calls return a {@see MemorySnapshot}
 * value object — but nothing is persisted. Replay (issue #112) explicitly
 * checks for database-backed snapshots and skips when missing, so this
 * binding keeps cache-driver tests and ephemeral workloads green without
 * polluting them with a useless table.
 *
 * @internal
 */
final class NullSnapshotsMemory implements SnapshotsMemory
{
    public function snapshot(string $runId, int $stepIndex): MemorySnapshot
    {
        return new MemorySnapshot($runId, $stepIndex, [], []);
    }

    public function appendToolCall(MemorySnapshot $snapshot, array $toolCall): MemorySnapshot
    {
        if ($snapshot->frozen) {
            throw SnapshotFrozenException::forStep($snapshot->runId, $snapshot->stepIndex);
        }

        return $snapshot->withToolCall($toolCall);
    }

    public function resetToolCalls(MemorySnapshot $snapshot): MemorySnapshot
    {
        return $snapshot->withClearedToolCalls();
    }

    public function find(string $runId, int $stepIndex): ?MemorySnapshot
    {
        return null;
    }
}
