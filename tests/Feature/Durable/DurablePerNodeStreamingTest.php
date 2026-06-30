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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableNonStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FlakyCoordinatorDurableStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\HierarchicalDurableStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StaticHierarchicalDurableStreamingSwarm;
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
    FlakyHierarchicalCoordinator::reset(failuresBeforeSuccess: 1);
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

// -----------------------------------------------------------------------------
// #311 — per-node streaming for hierarchical & static-hierarchical durable runs.
// The coordinator (step 0) ships STRUCTURAL-ONLY (#314 token-streaming deferred);
// the worker nodes stream token deltas per-node into the causal log.
// -----------------------------------------------------------------------------

/** Fake the coordinator to route writer_node (clean) → editor_node (flaky). */
function fakeHierarchicalStreamingPlan(): void
{
    FakeHierarchicalCoordinator::fake([
        [
            'start_at' => 'writer_node',
            'nodes' => [
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => PlainStreamEditor::class,
                    'prompt' => 'writer-task',
                    'next' => 'editor_node',
                ],
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => FlakyStreamEditor::class,
                    'prompt' => 'editor-task',
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'editor_node',
                ],
            ],
        ],
    ]);
}

test('a hierarchical durable run streams per node: coordinator structural-only, workers token-stream, crashed node voided and resumed clean (#311)', function () {
    fakeHierarchicalStreamingPlan();

    $response = HierarchicalDurableStreamingSwarm::make()->dispatchDurable('stream-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    // Step 0 — coordinator: STRUCTURAL-ONLY. It runs via prompt() (no token deltas)
    // but writes node-structure events under the reserved __coordinator__ id.
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    $coordinatorEvents = DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->where('node_id', '__coordinator__')
        ->pluck('event_type')
        ->all();

    expect($coordinatorEvents)->toContain('swarm_node_opened')
        ->and($coordinatorEvents)->toContain('swarm_node_children_decided')
        ->and($coordinatorEvents)->toContain('swarm_node_closed')
        // Structural-only: no token deltas streamed for the coordinator (#314 waiver).
        ->and($coordinatorEvents)->not->toContain('swarm_text_delta')
        ->and(DB::table('swarm_stream_events')
            ->where('run_id', $runId)
            ->where('node_id', '__coordinator__')
            ->where('attempt_epoch', 0)
            ->count())->toBe(count($coordinatorEvents));

    // Step 1 — writer_node: a clean worker token-streams under its plan node id.
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    expect(durableStreamEventCount($runId, 'writer_node'))->toBeGreaterThan(0)
        ->and(DB::table('swarm_stream_events')
            ->where('run_id', $runId)
            ->where('node_id', 'writer_node')
            ->where('event_type', 'swarm_text_delta')
            ->count())->toBeGreaterThan(0);

    // Step 2 — editor_node (flaky): streams a partial delta, then crashes mid-node;
    // the run schedules a retry.
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    $crashed = $manager->find($runId);
    expect($crashed['status'])->toBe('pending')
        ->and($crashed['recovery_count'])->toBe(0)
        ->and(FlakyStreamEditor::$attempts)->toBe(1)
        ->and(durableStreamEventCount($runId, 'editor_node'))->toBe(2); // node_opened + 1 partial delta

    // Recover past the backoff: recovery_count bumps to 1.
    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');

    // editor_node re-executes: it retracts the crashed attempt, then streams clean
    // under epoch 1, and the run completes.
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);
    expect(FlakyStreamEditor::$attempts)->toBe(2)
        ->and($manager->find($runId)['status'])->toBe('completed');

    // Exactly one node_reexecuted void-edge, for the crashed editor_node attempt.
    expect(DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)
        ->count())->toBe(1);

    $log = app(CausalLogStore::class);

    // Clean fold: editor_node shows only the resumed attempt (epoch 1); the crashed
    // partial output is gone.
    $cleanEditor = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Clean),
        fn ($event) => streamNodeId($event) === 'editor_node',
    ));

    expect($cleanEditor)->not->toBeEmpty();

    foreach ($cleanEditor as $event) {
        expect($event->attemptEpoch)->toBe(1);
    }

    $cleanDeltas = array_map(fn ($event) => $event->toArray()['delta'] ?? null, $cleanEditor);
    expect($cleanDeltas)->not->toContain('partial-1')
        ->and($cleanDeltas)->toContain('-done');

    // Everything fold: the crashed attempt survives, wrapped as voided. One
    // void-edge retracts the whole (editor_node, epoch 0) attempt, so every event
    // streamed under it — its node_opened and its partial delta — folds as voided.
    $voided = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Everything),
        fn ($event) => $event instanceof VoidedEvent && $event->voidType === CausalVoidEdgeType::NodeReexecuted,
    ));

    $voidedDeltas = array_map(fn ($event) => $event->event->toArray()['delta'] ?? null, $voided);
    expect($voidedDeltas)->toContain('partial-1');
});

test('a static-hierarchical durable run streams worker nodes token-by-token, voids the crashed node, and resumes clean (#311)', function () {
    $response = StaticHierarchicalDurableStreamingSwarm::make()->dispatchDurable('stream-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    // Step 0 — static init: no coordinator agent, so it builds the cursor without
    // any agent call. The only causal-log write is the step-boundary seal barrier
    // (a node-less internal compaction marker) — no node events stream here.
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    expect(DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->whereNotNull('node_id')
        ->count())->toBe(0);

    // Step 1 — writer_node: a clean worker token-streams under its plan node id.
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    expect(DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->where('node_id', 'writer_node')
        ->where('event_type', 'swarm_text_delta')
        ->count())->toBeGreaterThan(0);

    // A static plan has no coordinator, so its streamed worker opens at the root:
    // parent_node_id is null, the one behavioral difference from the dynamic
    // hierarchical runner's __coordinator__ parent (StaticHierarchicalRunner::
    // durableWorkerParentNodeId()).
    $writerOpened = DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->where('node_id', 'writer_node')
        ->where('event_type', 'swarm_node_opened')
        ->value('payload');

    $writerOpenedPayload = json_decode((string) $writerOpened, true);
    expect($writerOpenedPayload)->toHaveKey('parent_node_id')
        ->and($writerOpenedPayload['parent_node_id'])->toBeNull();

    // Step 2 — editor_node (flaky): partial delta then crash mid-node.
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);
    expect($manager->find($runId)['status'])->toBe('pending')
        ->and(durableStreamEventCount($runId, 'editor_node'))->toBe(2);

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');

    (new AdvanceDurableSwarm($runId, 2))->handle($manager);
    expect($manager->find($runId)['status'])->toBe('completed')
        ->and(DB::table('swarm_stream_events')
            ->where('run_id', $runId)
            ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)
            ->count())->toBe(1);

    $log = app(CausalLogStore::class);

    $cleanEditor = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Clean),
        fn ($event) => streamNodeId($event) === 'editor_node',
    ));

    expect($cleanEditor)->not->toBeEmpty();

    foreach ($cleanEditor as $event) {
        expect($event->attemptEpoch)->toBe(1);
    }

    $cleanDeltas = array_map(fn ($event) => $event->toArray()['delta'] ?? null, $cleanEditor);
    expect($cleanDeltas)->not->toContain('partial-1')
        ->and($cleanDeltas)->toContain('-done');
});

test('the operator kill-switch sheds hierarchical worker emission mid-run but the crashed node is still voided and sealed (KS1)', function () {
    fakeHierarchicalStreamingPlan();

    $response = HierarchicalDurableStreamingSwarm::make()->dispatchDurable('stream-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    // Coordinator (step 0) + writer_node (step 1) stream normally; editor_node
    // (step 2) crashes mid-node, leaving one partial event under epoch 0.
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);
    expect(durableStreamEventCount($runId, 'editor_node'))->toBe(2); // node_opened + 1 partial delta

    // Operator pauses durable streaming fleet-wide before the resume.
    config()->set('swarm.durable.streaming_enabled', false);

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');

    // editor_node re-executes under epoch 1: the kill-switch routes it through the
    // blocking prompt() fallback (no new node bracket, no stream deltas), but
    // integrity is independent of the kill-switch — the crashed attempt is still
    // retracted with a node_reexecuted void-edge and the run still completes.
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and(
            DB::table('swarm_stream_events')
                ->where('run_id', $runId)
                ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)
                ->count()
        )->toBe(1)
        // No epoch-1 stream rows for editor_node — emission was shed; the node was
        // sealed via prompt() rather than re-streamed.
        ->and(
            DB::table('swarm_stream_events')
                ->where('run_id', $runId)
                ->where('node_id', 'editor_node')
                ->where('attempt_epoch', 1)
                ->count()
        )->toBe(0);

    // The crashed attempt is gone from the clean fold; the seal-on-commit fence
    // means the resumed node committed cleanly even with emission shed.
    $log = app(CausalLogStore::class);
    $cleanEditor = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Clean),
        fn ($event) => streamNodeId($event) === 'editor_node',
    ));

    $cleanDeltas = array_map(fn ($event) => $event->toArray()['delta'] ?? null, $cleanEditor);
    expect($cleanDeltas)->not->toContain('partial-1');
});

test('a hierarchical coordinator that crashes before checkpoint is voided on resume, leaving one clean coordinator attempt (#311)', function () {
    $response = FlakyCoordinatorDurableStreamingSwarm::make()->dispatchDurable('stream-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    // Step 0 — coordinator: opens the __coordinator__ structural bracket via the
    // sink, then crashes inside prompt() before the children-decided/close +
    // checkpoint. The orphaned node_opened is left above the checkpoint under epoch 0.
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    $crashed = $manager->find($runId);
    expect($crashed['status'])->toBe('pending')
        ->and($crashed['recovery_count'])->toBe(0)
        ->and(FlakyHierarchicalCoordinator::$attempts)->toBe(1)
        // The crashed attempt's lone node_opened is in the log, not yet voided.
        ->and(durableStreamEventCount($runId, '__coordinator__'))->toBe(1)
        ->and(DB::table('swarm_stream_events')
            ->where('run_id', $runId)
            ->where('node_id', '__coordinator__')
            ->where('event_type', 'swarm_node_opened')
            ->where('attempt_epoch', 0)
            ->count())->toBe(1);

    // Recover past the backoff: recovery_count bumps to 1, so the resumed coordinator
    // re-plans under a strictly higher epoch than the crashed one.
    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');

    // Step 0 re-executes: voidPriorAttempt('__coordinator__', …) retracts the crashed
    // attempt, then the coordinator plans cleanly and brackets the run under epoch 1.
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    expect(FlakyHierarchicalCoordinator::$attempts)->toBe(2);

    // Drive the single worker to completion.
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    expect($manager->find($runId)['status'])->toBe('completed');

    // Exactly ONE node_reexecuted void-edge was written — for the crashed coordinator
    // attempt, not the worker.
    expect(DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)
        ->count())->toBe(1);

    $log = app(CausalLogStore::class);

    // Clean fold: the __coordinator__ events that survive all belong to the resumed
    // attempt (epoch 1); the crashed open is suppressed. A clean coordinator bracket
    // — open → children-decided → close — folds through.
    $cleanCoordinator = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Clean),
        fn ($event) => streamNodeId($event) === '__coordinator__',
    ));

    expect($cleanCoordinator)->not->toBeEmpty();

    foreach ($cleanCoordinator as $event) {
        expect($event->attemptEpoch)->toBe(1);
    }

    $cleanTypes = array_map(fn ($event) => $event->toArray()['type'] ?? null, $cleanCoordinator);
    expect($cleanTypes)->toContain('swarm_node_opened')
        ->and($cleanTypes)->toContain('swarm_node_children_decided')
        ->and($cleanTypes)->toContain('swarm_node_closed')
        // Exactly one open survives — the crashed attempt's open is voided away.
        ->and(count(array_filter($cleanTypes, fn ($type) => $type === 'swarm_node_opened')))->toBe(1);

    // Everything fold: the crashed coordinator open survives, wrapped as voided.
    $voidedCoordinator = array_values(array_filter(
        CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Everything),
        fn ($event) => $event instanceof VoidedEvent
            && streamNodeId($event) === '__coordinator__'
            && $event->voidType === CausalVoidEdgeType::NodeReexecuted,
    ));

    expect($voidedCoordinator)->toHaveCount(1)
        ->and($voidedCoordinator[0]->event->attemptEpoch)->toBe(0);
});
