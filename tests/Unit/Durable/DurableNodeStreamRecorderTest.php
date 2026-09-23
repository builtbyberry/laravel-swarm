<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableNodeStreamRecorder;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Streaming\View\VoidedEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    config()->set('swarm.persistence.driver', 'database');
});

function seedNodeStreamRun(string $runId): void
{
    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'running',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

test('enabled reflects the pinned opt-in and ignores the operator kill-switch', function () {
    $recorder = app(DurableNodeStreamRecorder::class);

    // enabled() is driven only by the pinned opt-in (+ database causal log), never
    // the kill-switch — integrity ops must run even while emission is paused.
    expect($recorder->enabled(false))->toBeFalse();
    expect($recorder->enabled(true))->toBeTrue();

    config()->set('swarm.durable.streaming_enabled', false);
    expect($recorder->enabled(true))->toBeTrue();
});

test('streamingActive honours the operator kill-switch on top of the pinned opt-in', function () {
    $recorder = app(DurableNodeStreamRecorder::class);

    // A run that did not opt in never emits, regardless of the kill-switch.
    expect($recorder->streamingActive(false))->toBeFalse();

    // A pinned run emits only while the kill-switch is on (default).
    expect($recorder->streamingActive(true))->toBeTrue();

    config()->set('swarm.durable.streaming_enabled', false);
    expect($recorder->streamingActive(true))->toBeFalse();
});

test('sealNodeBoundary and voidPriorAttempt are inert when the run is not pinned (the prompt path is untouched)', function () {
    seedNodeStreamRun('run-off');

    $recorder = app(DurableNodeStreamRecorder::class);
    $recorder->sealNodeBoundary('run-off', false);
    $recorder->voidPriorAttempt('run-off', 'step:0', 1, false);

    expect(DB::table('swarm_stream_events')->where('run_id', 'run-off')->count())->toBe(0);
});

test('integrity ops still run when the kill-switch pauses emission (KS1)', function () {
    // Kill-switch off, but the run is pinned: a prior streamed attempt must still be
    // voided and the boundary still sealed, so the causal-log fold never orphans.
    config()->set('swarm.durable.streaming_enabled', false);
    seedNodeStreamRun('run-ks');

    // The crashed attempt streamed one event under epoch 0 (sinkFor never gates).
    $sink0 = app(DurableNodeStreamRecorder::class)->sinkFor('run-ks', 'step:0', 0);
    $sink0(new SwarmTextDelta(
        id: 'ks-crashed-event',
        runId: 'run-ks',
        stepIndex: 0,
        agentClass: 'ExampleAgent',
        delta: 'partial',
        timestamp: SwarmStreamEvent::timestamp(),
    ));

    $recorder = app(DurableNodeStreamRecorder::class);
    $recorder->voidPriorAttempt('run-ks', 'step:0', 1, true);
    $recorder->sealNodeBoundary('run-ks', true);

    expect(
        DB::table('swarm_stream_events')->where('run_id', 'run-ks')->where('void_type', 'node_reexecuted')->count()
    )->toBe(1)
        ->and(
            DB::table('swarm_stream_events')->where('run_id', 'run-ks')->where('event_type', 'swarm_causal_seal_barrier')->count()
        )->toBe(1);
});

test('sinkFor stamps the node id and attempt epoch onto each event and appends it', function () {
    seedNodeStreamRun('run-sink');

    $sink = app(DurableNodeStreamRecorder::class)->sinkFor('run-sink', 'step:2', 4);
    $sink(new SwarmTextDelta(
        id: 'sink-event-1',
        runId: 'run-sink',
        stepIndex: 2,
        agentClass: 'ExampleAgent',
        delta: 'hi',
        timestamp: SwarmStreamEvent::timestamp(),
    ));

    $row = DB::table('swarm_stream_events')->where('run_id', 'run-sink')->first();
    expect($row->node_id)->toBe('step:2')
        ->and((int) $row->attempt_epoch)->toBe(4)
        ->and($row->event_uuid)->toBe('sink-event-1');
});

test('two branch sinks in one worker carry distinct (node_id, epoch) stamps (#312 Octane L3)', function () {
    // Per-branch sink closures are call-scoped: two concurrent durable parallel
    // branches sharing one Octane worker each build their own sink, capturing their
    // own (branch node id, branch epoch) in closure scope. Proven by interleaving the
    // two sinks' emissions — neither stamps the other's identity onto its events.
    seedNodeStreamRun('run-branches');

    $recorder = app(DurableNodeStreamRecorder::class);
    $branchA = $recorder->sinkFor('run-branches', 'parallel:0', 1); // crashed branch, epoch 1
    $branchB = $recorder->sinkFor('run-branches', 'parallel:1', 1); // sibling branch, epoch 1

    $event = static fn (string $id): SwarmTextDelta => new SwarmTextDelta(
        id: $id,
        runId: 'run-branches',
        stepIndex: 0,
        agentClass: 'ExampleAgent',
        delta: 'd',
        timestamp: SwarmStreamEvent::timestamp(),
    );

    // Interleave the two sinks to prove neither closure leaks the other's identity.
    $branchA($event('a-1'));
    $branchB($event('b-1'));
    $branchA($event('a-2'));

    $stamp = fn (string $uuid): array => (array) DB::table('swarm_stream_events')
        ->where('run_id', 'run-branches')->where('event_uuid', $uuid)
        ->first(['node_id', 'attempt_epoch']);

    expect($stamp('a-1'))->toMatchArray(['node_id' => 'parallel:0', 'attempt_epoch' => 1])
        ->and($stamp('a-2'))->toMatchArray(['node_id' => 'parallel:0', 'attempt_epoch' => 1])
        ->and($stamp('b-1'))->toMatchArray(['node_id' => 'parallel:1', 'attempt_epoch' => 1]);
});

test('sealNodeBoundary appends one seal barrier when the run is pinned', function () {
    seedNodeStreamRun('run-seal');

    app(DurableNodeStreamRecorder::class)->sealNodeBoundary('run-seal', true);

    expect(
        DB::table('swarm_stream_events')
            ->where('run_id', 'run-seal')
            ->where('event_type', 'swarm_causal_seal_barrier')
            ->count()
    )->toBe(1);
});

test('voidPriorAttempt retracts the prior epoch before a fresh attempt re-emits', function () {
    seedNodeStreamRun('run-resume');

    // The crashed attempt streamed one event under epoch 0.
    $sink0 = app(DurableNodeStreamRecorder::class)->sinkFor('run-resume', 'step:0', 0);
    $sink0(new SwarmTextDelta(
        id: 'crashed-event',
        runId: 'run-resume',
        stepIndex: 0,
        agentClass: 'ExampleAgent',
        delta: 'partial',
        timestamp: SwarmStreamEvent::timestamp(),
    ));

    // Resume runs under epoch 1 and retracts epoch 0.
    app(DurableNodeStreamRecorder::class)->voidPriorAttempt('run-resume', 'step:0', 1, true);

    $edge = DB::table('swarm_stream_events')
        ->where('run_id', 'run-resume')
        ->where('void_type', 'node_reexecuted')
        ->first();

    expect($edge)->not->toBeNull()
        ->and($edge->void_target_event_uuid)->toBe('crashed-event');
});

test('repeated native partial and final IDs stay isolated across durable nodes and attempts', function (string $seam) {
    seedNodeStreamRun('run-tool-attempts');
    $recorder = app(DurableNodeStreamRecorder::class);
    $old = $recorder->sinkFor('run-tool-attempts', 'parallel:0', 1);
    $sibling = $recorder->sinkFor('run-tool-attempts', 'parallel:1', 1);
    $result = static fn (string $id, string $value, bool $partial): SwarmToolResult => new SwarmToolResult(
        $id, 'run-tool-attempts', 0, 'ExampleAgent', new ToolResult('same-call', 'tool', [], $value), true, null, 123,
        preliminary: $partial,
    );
    $old($result('same-partial', 'old partial', true));
    $sibling($result('same-partial', 'sibling partial', true));
    $recorder->voidPriorAttempt('run-tool-attempts', 'parallel:0', 2, true);
    $fresh = $recorder->sinkFor('run-tool-attempts', 'parallel:0', 2);
    $fresh($result('same-partial', 'fresh partial', true));
    $old($result('same-final', 'late old final', false));
    $sibling($result('same-final', 'sibling final', false));
    $fresh($result('same-final', 'fresh final', false));
    $recorder->sealNodeBoundary('run-tool-attempts', true);
    $visible = collect(CausalLogView::forRun(app(StreamEventStore::class), 'run-tool-attempts')->fold())->whereInstanceOf(SwarmToolResult::class)->values();
    expect($visible->map(fn (SwarmToolResult $event) => $event->toolResult->result)->all())->toBe([
        'sibling partial', 'fresh partial', 'sibling final', 'fresh final',
    ])->and($visible->pluck('preliminary')->all())->toBe([true, true, false, false]);
    $expected = $visible->map(fn (SwarmToolResult $event) => $event->toArray())->all();
    foreach ($expected as $wire) {
        expect($wire)->not->toHaveKeys(['storage_event_uuid', 'storageEventId']);
    }
    $snapshot = CausalLogView::forRun(app(StreamEventStore::class), 'run-tool-attempts')->snapshot();
    $restored = new CausalLogView(array_map(fn (array $wire) => SwarmStreamEvent::fromArray($wire), $snapshot['events']));
    expect(collect($restored->fold())->whereInstanceOf(SwarmToolResult::class)->map(fn ($event) => $event->toArray())->values()->all())->toBe($expected);
    if ($seam !== 'hot') {
        $cold = app(DatabaseColdArchiveDriver::class);
        $rows = DB::table('swarm_stream_events')->where('run_id', 'run-tool-attempts');
        $boundary = ($seam === 'split' ? $rows->min('id') : $rows->max('id')) + 1;
        $sealed = app(SwarmPersistenceCipher::class)->seal(json_encode($snapshot, JSON_THROW_ON_ERROR));
        expect($cold->graduate('run-tool-attempts', 0, $boundary, $sealed))->toBeTrue();
        $cold->reclaim('run-tool-attempts', $boundary);
        app()->forgetInstance(StreamEventStore::class);
        $reloaded = collect(CausalLogView::forRun(app(StreamEventStore::class), 'run-tool-attempts')->fold())->whereInstanceOf(SwarmToolResult::class);
        expect($reloaded->map(fn ($event) => $event->toArray())->values()->all())->toBe($expected);
    }
})->with(['hot', 'cold', 'split']);

test('late function-tool events without prior anchors remain invalid after recovery', function () {
    seedNodeStreamRun('run-late-tools');
    $recorder = app(DurableNodeStreamRecorder::class);
    $recorder->voidPriorAttempt('run-late-tools', 'node', 2, true);
    foreach ([1, 2] as $epoch) {
        $sink = $recorder->sinkFor('run-late-tools', 'node', $epoch);
        $sink(new SwarmToolCall('call', 'run-late-tools', 0, 'Agent', new ToolCall('tool-id', 'tool', []), 123));
        $sink(new SwarmToolResult('final', 'run-late-tools', 0, 'Agent', new ToolResult('tool-id', 'tool', [], 'epoch-'.$epoch), true, null, 123));
    }
    $view = CausalLogView::forRun(app(StreamEventStore::class), 'run-late-tools');
    $visible = collect($view->fold())->filter(fn ($event) => $event instanceof SwarmToolResult || $event instanceof SwarmToolCall);
    expect($visible)->toHaveCount(2)->and($visible->pluck('attemptEpoch')->unique()->values()->all())->toBe([2]);
    $audit = collect($view->fold(supersession: ViewSupersession::Everything));
    expect($audit->whereInstanceOf(VoidedEvent::class))->toHaveCount(2);
});

test('legacy raw function-tool identities remain valid void targets through cold storage', function () {
    seedNodeStreamRun('run-legacy-tool');
    $recorder = app(DurableNodeStreamRecorder::class);
    $recorder->sinkFor('run-legacy-tool', 'node', 0)(new SwarmToolResult('legacy-native', 'run-legacy-tool', 0, 'Agent', new ToolResult('call', 'tool', [], 'old'), true, null, 123));
    $row = DB::table('swarm_stream_events')->where('run_id', 'run-legacy-tool')->first();
    $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    unset($payload['preliminary'], $payload['denied'], $payload['attempt_epoch']);
    DB::table('swarm_stream_events')->where('id', $row->id)->update(['event_uuid' => 'legacy-native', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    app(CausalLogStore::class)->voidNodeAttempt('run-legacy-tool', 'node', 0, 'legacy retry');
    $recorder->sinkFor('run-legacy-tool', 'node', 1)(new SwarmToolResult('new-native', 'run-legacy-tool', 0, 'Agent', new ToolResult('call', 'tool', [], 'new'), true, null, 123));
    $cold = app(DatabaseColdArchiveDriver::class);
    $boundary = DB::table('swarm_stream_events')->where('run_id', 'run-legacy-tool')->max('id') + 1;
    expect($cold->graduate('run-legacy-tool', 0, $boundary, '{}'))->toBeTrue();
    $cold->reclaim('run-legacy-tool', $boundary);
    $events = collect(CausalLogView::forRun(app(StreamEventStore::class), 'run-legacy-tool')->fold())->whereInstanceOf(SwarmToolResult::class);
    expect($events->pluck('id')->values()->all())->toBe(['new-native']);
});
