<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Streaming\View\VoidedEvent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableNonStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\HierarchicalDurableStreamingSwarm;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Mirror DurableSwarmTest's durable runtime setup, self-contained for this file. */
function configureDurablePerNodeStreamingRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([
        ContextStore::class,
        ArtifactRepository::class,
        RunHistoryStore::class,
        DurableRunStore::class,
        SwarmRunner::class,
        DurableSwarmManager::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

/** node_id stamped on an event's payload, or null for a barrier / top-level event. */
function streamNodeId(SwarmStreamEvent|VoidedEvent $event): ?string
{
    $payload = ($event instanceof VoidedEvent ? $event->event : $event)->toArray();

    return is_string($payload['node_id'] ?? null) ? $payload['node_id'] : null;
}

function durableStreamEventCount(string $runId, ?string $nodeId = null): int
{
    $query = DB::table('swarm_stream_events')->where('run_id', $runId);

    if ($nodeId !== null) {
        $query->where('node_id', $nodeId);
    }

    return $query->count();
}

beforeEach(function () {
    configureDurablePerNodeStreamingRuntime();
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    FlakyStreamEditor::reset(failuresBeforeSuccess: 1);
});

test('the durable-streaming opt-in is resolved from the attribute and pinned on the run row at run-start', function () {
    FlakyStreamEditor::reset(failuresBeforeSuccess: 0);

    $optedIn = DurableStreamingSwarm::make()->dispatchDurable('stream-task')->runId;
    $optedOut = DurableNonStreamingSwarm::make()->dispatchDurable('stream-task')->runId;

    expect((bool) DB::table('swarm_durable_runs')->where('run_id', $optedIn)->value('durable_streaming'))->toBeTrue()
        ->and((bool) DB::table('swarm_durable_runs')->where('run_id', $optedOut)->value('durable_streaming'))->toBeFalse();
});

test('a 3-node durable run streams per node, voids the crashed node, and keeps the resumed node clean', function () {
    $response = DurableStreamingSwarm::make()->dispatchDurable('stream-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    // Step 0 streams cleanly and checkpoints (attempt epoch 0).
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    expect(durableStreamEventCount($runId, 'step:0'))->toBeGreaterThan(0);

    // Step 1 streams a partial delta, then crashes mid-node; the run schedules a retry.
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    $crashed = $manager->find($runId);
    expect($crashed['status'])->toBe('pending')
        ->and($crashed['retry_attempt'])->toBe(1)
        ->and($crashed['recovery_count'])->toBe(0)
        ->and(FlakyStreamEditor::$attempts)->toBe(1)
        // The crashed attempt's one partial event is in the log, not yet voided.
        ->and(durableStreamEventCount($runId, 'step:1'))->toBe(1);

    // Recover past the backoff: recovery_count bumps to 1, so the resumed attempt
    // streams under a strictly higher epoch than the crashed one.
    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');

    // Step 1 re-executes: it retracts the crashed attempt (a node_reexecuted edge),
    // then streams cleanly under epoch 1.
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    expect(FlakyStreamEditor::$attempts)->toBe(2);

    // Step 2 streams cleanly and the run completes.
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);
    expect($manager->find($runId)['status'])->toBe('completed');

    // Exactly one node_reexecuted void-edge was written, for the crashed attempt.
    expect(
        DB::table('swarm_stream_events')
            ->where('run_id', $runId)
            ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)
            ->count()
    )->toBe(1);

    $log = app(CausalLogStore::class);

    // Clean fold: step:1 shows only the resumed attempt (epoch 1); the crashed
    // partial output is gone.
    $cleanStep1 = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Clean),
        fn ($event) => streamNodeId($event) === 'step:1',
    ));

    expect($cleanStep1)->not->toBeEmpty();

    foreach ($cleanStep1 as $event) {
        expect($event->attemptEpoch)->toBe(1);
    }

    $cleanDeltas = array_map(fn ($event) => $event->toArray()['delta'] ?? null, $cleanStep1);
    expect($cleanDeltas)->not->toContain('partial-1')   // crashed attempt suppressed
        ->and($cleanDeltas)->toContain('-done');         // resumed attempt present

    // Everything fold: the crashed event survives, wrapped as voided.
    $voided = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Everything),
        fn ($event) => $event instanceof VoidedEvent && $event->voidType === CausalVoidEdgeType::NodeReexecuted,
    ));

    expect($voided)->toHaveCount(1)
        ->and($voided[0]->event->toArray()['delta'] ?? null)->toBe('partial-1');
});

test('a durable swarm without the #[DurableStreaming] attribute writes no causal-log events', function () {
    FlakyStreamEditor::reset(failuresBeforeSuccess: 0);

    $response = DurableNonStreamingSwarm::make()->dispatchDurable('stream-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and(durableStreamEventCount($runId))->toBe(0);
});

test('the operator kill-switch sheds emission mid-run but the crashed attempt is still voided (KS1)', function () {
    $response = DurableStreamingSwarm::make()->dispatchDurable('stream-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    // Step 0 + step 1 stream normally; step 1 crashes mid-node, leaving one partial
    // event under epoch 0.
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    expect(durableStreamEventCount($runId, 'step:1'))->toBe(1);

    // Operator pauses durable streaming fleet-wide before the resume.
    config()->set('swarm.durable.streaming_enabled', false);

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');

    // Step 1 re-executes under epoch 1: the kill-switch routes it through prompt()
    // (no new stream deltas), but integrity is untouched — the crashed attempt is
    // still retracted with a node_reexecuted void-edge.
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and(
            DB::table('swarm_stream_events')
                ->where('run_id', $runId)
                ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)
                ->count()
        )->toBe(1)
        // No epoch-1 stream deltas for step:1 — emission was shed.
        ->and(
            DB::table('swarm_stream_events')
                ->where('run_id', $runId)
                ->where('node_id', 'step:1')
                ->where('attempt_epoch', 1)
                ->where('event_type', 'swarm_text_delta')
                ->count()
        )->toBe(0);
});

test('durable per-node streaming dispatch fails loud when the causal log is not migrated for streaming (#298 F7)', function () {
    Schema::table('swarm_stream_events', function (Blueprint $table): void {
        $table->dropIndex('swarm_stream_events_run_node_epoch_index');
        $table->dropColumn(['node_id', 'attempt_epoch']);
    });

    expect(fn () => DurableStreamingSwarm::make()->dispatchDurable('stream-task'))
        ->toThrow(SwarmException::class);
});

test('#[DurableStreaming] on a non-sequential topology fails loud at dispatch until #311/#312 (#310 forcing function)', function () {
    // End-to-end proof the topology guard is wired into the real dispatch path: a
    // hierarchical swarm that opts in must NOT silently pin durable_streaming and
    // no-op — it fails loud at dispatchDurable. Delete when #311 wires hierarchical.
    expect(fn () => HierarchicalDurableStreamingSwarm::make()->dispatchDurable('stream-task'))
        ->toThrow(SwarmException::class, 'currently supported only for sequential');
});
