<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableNodeStreamRecorder;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

test('enabled reflects the per-node streaming opt-in flag', function () {
    $recorder = app(DurableNodeStreamRecorder::class);

    config()->set('swarm.durable.stream_to_causal_log', false);
    expect($recorder->enabled())->toBeFalse();

    config()->set('swarm.durable.stream_to_causal_log', true);
    expect($recorder->enabled())->toBeTrue();
});

test('sealNodeBoundary and voidPriorAttempt are inert when streaming is off (the prompt path is untouched)', function () {
    config()->set('swarm.durable.stream_to_causal_log', false);
    seedNodeStreamRun('run-off');

    $recorder = app(DurableNodeStreamRecorder::class);
    $recorder->sealNodeBoundary('run-off');
    $recorder->voidPriorAttempt('run-off', 'step:0', 1);

    expect(DB::table('swarm_stream_events')->where('run_id', 'run-off')->count())->toBe(0);
});

test('sinkFor stamps the node id and attempt epoch onto each event and appends it', function () {
    config()->set('swarm.durable.stream_to_causal_log', true);
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

test('sealNodeBoundary appends one seal barrier when streaming is on', function () {
    config()->set('swarm.durable.stream_to_causal_log', true);
    seedNodeStreamRun('run-seal');

    app(DurableNodeStreamRecorder::class)->sealNodeBoundary('run-seal');

    expect(
        DB::table('swarm_stream_events')
            ->where('run_id', 'run-seal')
            ->where('event_type', 'swarm_causal_seal_barrier')
            ->count()
    )->toBe(1);
});

test('voidPriorAttempt retracts the prior epoch before a fresh attempt re-emits', function () {
    config()->set('swarm.durable.stream_to_causal_log', true);
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
    app(DurableNodeStreamRecorder::class)->voidPriorAttempt('run-resume', 'step:0', 1);

    $edge = DB::table('swarm_stream_events')
        ->where('run_id', 'run-resume')
        ->where('void_type', 'node_reexecuted')
        ->first();

    expect($edge)->not->toBeNull()
        ->and($edge->void_target_event_uuid)->toBe('crashed-event');
});
