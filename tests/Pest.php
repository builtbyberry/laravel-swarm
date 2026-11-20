<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrencyTestCase;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Tests\TestCase;
use Illuminate\Support\Facades\File;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(ProcessConcurrencyTestCase::class)->in('ProcessConcurrency');

// Installer tests bind their own base case via `uses(SwarmInstallerTestCase::class)`
// inside each file so each test gets an isolated host-app skeleton — see
// tests/Installer/README.md.

/**
 * Run a test body against a private application base path.
 *
 * Every test process shares one testbench skeleton, so a file written under
 * its `stubs/` directory is visible to generator commands running in other
 * parallel workers. A test that publishes a custom stub does it in here: the
 * stub, and the class generated from it, live in a temporary directory that
 * only this test can see and that is removed afterwards.
 */
function withIsolatedBasePath(Closure $callback): void
{
    $app = app();
    $shared = $app->basePath();
    $isolated = sys_get_temp_dir().'/swarm-base-'.bin2hex(random_bytes(8));

    try {
        File::ensureDirectoryExists($isolated.'/stubs');
        File::ensureDirectoryExists($isolated.'/app');

        // The generators read the root namespace from the application's composer.json.
        if (! File::copy($shared.'/composer.json', $isolated.'/composer.json')) {
            throw new RuntimeException('Unable to prepare an isolated application base path.');
        }

        $app->setBasePath($isolated);

        $callback();
    } finally {
        $app->setBasePath($shared);
        File::deleteDirectory($isolated);
    }
}

function bindMemoryCapturePolicy(MemoryCapturePolicy $policy): void
{
    app()->instance(MemoryCapturePolicy::class, $policy);
    app()->forgetInstance(MemoryStore::class);
    app()->forgetInstance(SwarmMemory::class);
}

dataset('decline causes', [
    'empty key' => [[
        'skip' => false,
        'arguments' => ['key' => '', 'value' => 'x'],
        'message' => 'A memory key is required.',
    ]],
    'capture-policy skip' => [[
        'skip' => true,
        'arguments' => ['key' => 'secret', 'value' => 'x'],
        'message' => 'The entry [secret] was not stored. Do not retry this write.',
    ]],
]);

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
