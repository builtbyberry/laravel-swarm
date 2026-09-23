<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Responses\ProviderToolData;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableNodeStreamRecorder;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolEvent;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\Facades\DB;

// Uses a real shared DB because each branch runs in an independent process.
pest()->group('process-concurrency', 'skip-locked-real-db');

/**
 * True if the configured testing connection is a real, shared DB engine the process
 * concurrency driver can reach from child processes. SQLite (the testbench default)
 * is excluded: :memory: is per-process. sqlsrv is excluded to mirror the sibling lanes.
 */
function providerBranchConcurrencySupported(): bool
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
function providerBranchWorker(string $runId, string $nodeId, int $epoch, int $count): Closure
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
            $sink(new SwarmProviderToolEvent('native-'.$i, $runId, 0, 'Agent', 'same-item', 'search_call', 'searching', 'fixture', 123,
                ProviderToolData::capture(['branch' => $nodeId, 'sequence' => $i])));
        }

        return ['node_id' => $nodeId, 'epoch' => $epoch, 'emitted' => $count];
    };
}

beforeEach(function (): void {
    if (! providerBranchConcurrencySupported()) {
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

test('provider events with repeated native IDs preserve branch scopes under process interleaving', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    $now = now('UTC');
    $runId = 'provider-branch-race';
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
        providerBranchWorker($runId, 'parallel:0', 1, $perBranch),
        providerBranchWorker($runId, 'parallel:1', 1, $perBranch),
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
    $events = collect(app(StreamEventStore::class)->events($runId))->whereInstanceOf(SwarmProviderToolEvent::class);
    expect($events)->toHaveCount($perBranch * 2)
        ->and($events->map->causalId()->unique())->toHaveCount($perBranch * 2);
    foreach ($events->groupBy('nodeId') as $node => $branch) {
        expect($branch->map(fn ($e) => $e->payload->data['sequence'])->values()->all())->toBe(range(0, $perBranch - 1))
            ->and($branch->map(fn ($e) => $e->payload->data['branch'])->unique()->values()->all())->toBe([$node]);
    }
    app(DurableNodeStreamRecorder::class)->voidPriorAttempt($runId, 'parallel:0', 2, true);
    $effective = collect(CausalLogView::forRun(app(StreamEventStore::class), $runId)->fold())->whereInstanceOf(SwarmProviderToolEvent::class);
    expect($effective)->toHaveCount($perBranch)->and($effective->pluck('nodeId')->unique()->values()->all())->toBe(['parallel:1']);

});
