<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrencyTestCase;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(ProcessConcurrencyTestCase::class)->in('ProcessConcurrency');

// Installer tests bind their own base case via `uses(InstallerTestCase::class)`
// inside each file so each test gets an isolated host-app skeleton — see
// tests/Installer/README.md.

/**
 * Flatten every MemoryEntry the runners handed to the recorder across all
 * snapshot calls — the agent-visible view every live runner froze. Shared by
 * the propagation and scope-isolation suites.
 *
 * @return array<int, MemoryEntry>
 */
function capturedEntries(RecordingSnapshotsMemory $recorder): array
{
    $entries = [];

    foreach ($recorder->snapshotCalls as $call) {
        foreach ($call['entries'] ?? [] as $entry) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

/**
 * Flatten the captured entries belonging to a single run — the per-run grouping
 * the concurrent-isolation tests use to prove one run never sees another's
 * Run-scoped memory.
 *
 * @return array<int, MemoryEntry>
 */
function capturedEntriesForRun(RecordingSnapshotsMemory $recorder, string $runId): array
{
    $entries = [];

    foreach ($recorder->snapshotCalls as $call) {
        if (($call['run_id'] ?? null) !== $runId) {
            continue;
        }

        foreach ($call['entries'] ?? [] as $entry) {
            $entries[] = $entry;
        }
    }

    return $entries;
}
