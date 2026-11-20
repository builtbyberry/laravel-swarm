<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableNodeStreamRecorder;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\Facades\DB;

/**
 * Process-concurrency coverage for the per-branch durable streaming sink (#312).
 *
 * Two durable parallel branches run as INDEPENDENT durable jobs writing into one
 * run-scoped causal log. Gate L3 (Octane): each branch builds its own sink closure
 * per advance, capturing its own (branch node id, branch epoch) in closure scope —
 * so two branches sharing one worker (or, here, two real worker processes) never
 * stamp each other's identity onto their events.
 *
 * The deterministic proof of closure isolation is the unit test
 * (tests/Unit/Durable/DurableNodeStreamRecorderTest.php — "two branch sinks in one
 * worker carry distinct (node_id, epoch) stamps"). This test's complementary job is
 * to run the two sinks head-on in two real processes against a shared engine and
 * assert every persisted row carries exactly its own branch's stamp — never a
 * cross-contaminated (node_id, epoch) pair.
 */
// Tagged `skip-locked-real-db` so the CI lane excludes it under `--fail-on-skipped`:
// it needs a shared MySQL/Postgres connection reachable from the child processes the
// concurrency driver spawns; in-memory SQLite is per-process and unreachable.
pest()->group('process-concurrency', 'skip-locked-real-db');

/**
 * True if the configured testing connection is a real, shared DB engine the process
 * concurrency driver can reach from child processes. SQLite (the testbench default)
 * is excluded: :memory: is per-process. sqlsrv is excluded to mirror the sibling lanes.
 */
function durableBranchSinkConcurrencyDriverSupported(): bool
{
    return ! in_array(
        DB::connection()->getDriverName(),
        ['sqlite', 'sqlsrv'],
        true,
    );
}

/**
 * Branch sink worker: build a sink for one branch (node id + epoch) and emit a fixed
 * run of events through it. A free function so its scope class is null and it
 * serializes cleanly to the child PHP process (no Pest runtime, no $this) — the same
 * idiom the sibling concurrency lanes use.
 */
function durableBranchSinkWorker(string $runId, string $nodeId, int $epoch, int $count): Closure
{
    return static function () use ($runId, $nodeId, $epoch, $count): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $sink = app(DurableNodeStreamRecorder::class)->sinkFor($runId, $nodeId, $epoch);

        for ($i = 0; $i < $count; $i++) {
            $sink(new SwarmTextDelta(
                id: $nodeId.'-event-'.$i,
                runId: $runId,
                stepIndex: 0,
                agentClass: 'ExampleAgent',
                delta: 'd'.$i,
                timestamp: SwarmStreamEvent::timestamp(),
            ));
        }

        return ['node_id' => $nodeId, 'epoch' => $epoch, 'emitted' => $count];
    };
}

beforeEach(function (): void {
    if (! durableBranchSinkConcurrencyDriverSupported()) {
        $this->markTestSkipped(
            'Durable branch-sink concurrency test requires a shared database engine '
            .'reachable from child processes (mysql/pgsql). Current driver: '
            .DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');

    DB::table('swarm_stream_events')->delete();
    DB::table('swarm_run_histories')->delete();
});

test('two branch sinks emitting concurrently in separate processes each carry only their own (node_id, epoch) stamp', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    $now = now('UTC');
    $runId = 'branch-sink-race';
    $perBranch = 25;

    // Parent history row the stream-event FK (run_id -> swarm_run_histories) needs.
    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'parallel',
        'status' => 'running',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Two branches, both on their first attempt (epoch 1), distinct branch node ids —
    // the top-level-parallel branch_id fallback (gate H3). Run head-on in two processes.
    [$resultA, $resultB] = $concurrency->run([
        durableBranchSinkWorker($runId, 'parallel:0', 1, $perBranch),
        durableBranchSinkWorker($runId, 'parallel:1', 1, $perBranch),
    ]);

    expect($resultA['emitted'])->toBe($perBranch)
        ->and($resultB['emitted'])->toBe($perBranch);

    // Every parallel:0 row is stamped (parallel:0, 1); every parallel:1 row is
    // (parallel:1, 1). No row carries the other branch's node id — closure isolation
    // held across real processes.
    $rows = DB::table('swarm_stream_events')->where('run_id', $runId)
        ->get(['node_id', 'attempt_epoch']);

    expect($rows)->toHaveCount($perBranch * 2);

    $byNode = $rows->groupBy('node_id');
    expect($byNode->keys()->sort()->values()->all())->toBe(['parallel:0', 'parallel:1']);

    foreach ($byNode as $nodeId => $nodeRows) {
        expect($nodeRows)->toHaveCount($perBranch);
        foreach ($nodeRows as $row) {
            expect((int) $row->attempt_epoch)->toBe(1)
                ->and($row->node_id)->toBe($nodeId);
        }
    }
});

/**
 * Batching branch sink worker: like {@see durableBranchSinkWorker()} with
 * `swarm.durable.stream_batching` on in the child. After its first event and the
 * held deltas, it waits at a file barrier until every branch holds a pending
 * batch, then flushes — so the two multi-row INSERTs happen while both
 * processes hold rows.
 */
function durableBatchingBranchSinkWorker(string $runId, string $nodeId, int $epoch, int $count, string $barrierDir, int $parties): Closure
{
    return static function () use ($runId, $nodeId, $epoch, $count, $barrierDir, $parties): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        config()->set('swarm.durable.stream_batching', [
            'enabled' => true,
            'max_events' => 100,
            'max_bytes' => 1_048_576,
            'max_age_ms' => 600_000,
        ]);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $sink = app(DurableNodeStreamRecorder::class)->sinkFor($runId, $nodeId, $epoch);

        for ($i = 0; $i < $count; $i++) {
            $sink(new SwarmTextDelta(
                id: $nodeId.'-event-'.$i,
                runId: $runId,
                stepIndex: 0,
                agentClass: 'ExampleAgent',
                delta: 'd'.$i,
                timestamp: SwarmStreamEvent::timestamp(),
            ));
        }

        touch($barrierDir.'/'.str_replace(':', '-', $nodeId).'.ready');
        $deadline = microtime(true) + 10;
        while (count(glob($barrierDir.'/*.ready') ?: []) < $parties) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Branch [{$nodeId}] did not see every branch reach the barrier.");
            }
            usleep(5_000);
        }

        $writtenBeforeFlush = DB::table('swarm_stream_events')->where('run_id', $runId)->where('node_id', $nodeId)->count();
        $sink->flush();

        return ['node_id' => $nodeId, 'pid' => getmypid(), 'written_before_flush' => $writtenBeforeFlush];
    };
}

test('two batching branch sinks in separate processes each write their own rows, in emission order', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    $now = now('UTC');
    $runId = 'branch-sink-batching-race';
    $perBranch = 25;
    $barrierDir = sys_get_temp_dir().'/swarm-batching-barrier-'.bin2hex(random_bytes(6));
    mkdir($barrierDir);

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'parallel',
        'status' => 'running',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    try {
        [$resultA, $resultB] = $concurrency->driver('process')->run([
            durableBatchingBranchSinkWorker($runId, 'parallel:0', 1, $perBranch, $barrierDir, 2),
            durableBatchingBranchSinkWorker($runId, 'parallel:1', 1, $perBranch, $barrierDir, 2),
        ]);
    } finally {
        array_map('unlink', glob($barrierDir.'/*') ?: []);
        rmdir($barrierDir);
    }

    // Two real processes, each holding all but its first event when it reached
    // the barrier.
    expect($resultA['pid'])->not->toBe($resultB['pid'])
        ->and($resultA['pid'])->not->toBe(getmypid())
        ->and($resultA['written_before_flush'])->toBe(1)
        ->and($resultB['written_before_flush'])->toBe(1);

    foreach (['parallel:0', 'parallel:1'] as $nodeId) {
        $rows = DB::table('swarm_stream_events')->where('run_id', $runId)->where('node_id', $nodeId)
            ->orderBy('id')->get(['event_uuid', 'attempt_epoch']);

        expect($rows->pluck('event_uuid')->all())
            ->toBe(array_map(fn (int $i): string => $nodeId.'-event-'.$i, range(0, $perBranch - 1)))
            ->and($rows->pluck('attempt_epoch')->map(fn ($e): int => (int) $e)->unique()->all())->toBe([1]);
    }
});
