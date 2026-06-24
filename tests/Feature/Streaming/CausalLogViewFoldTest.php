<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewOrder;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Streaming\View\VoidedEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

/**
 * Seed the parent swarm_run_histories row the stream-event FK requires.
 */
function seedFoldRun(string $runId): void
{
    $now = now('UTC');

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
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * @param  array<int, SwarmStreamEvent|VoidedEvent>  $folded
 * @return list<string>
 */
function foldedIds(array $folded): array
{
    return array_map(function ($row): string {
        $event = $row instanceof VoidedEvent ? $row->event : $row;

        return (string) ($event->toArray()['id'] ?? '');
    }, $folded);
}

test('folds a real persisted log: clean view drops a superseded event, everything view exposes it', function () {
    $store = app(CausalLogStore::class);
    seedFoldRun('run-fold-1');

    $store->record('run-fold-1', new SwarmStreamStart(
        id: 'start-1',
        runId: 'run-fold-1',
        swarmClass: 'ExampleSwarm',
        topology: 'sequential',
        input: 'the prompt',
        metadata: [],
        timestamp: SwarmStreamEvent::timestamp(),
    ), 0);

    $store->record('run-fold-1', new SwarmTextDelta(
        id: 'delta-1',
        runId: 'run-fold-1',
        stepIndex: 0,
        agentClass: 'ExampleAgent',
        delta: 'draft',
        timestamp: SwarmStreamEvent::timestamp(),
    ), 0);

    $store->appendVoidEdge('run-fold-1', CausalVoidEdgeType::Supersedes, 'delta-1', 'first draft revised');

    $view = CausalLogView::forRun($store, 'run-fold-1');

    $clean = foldedIds($view->fold(ViewOrder::Causal, ViewSupersession::Clean));
    $everything = $view->fold(ViewOrder::Causal, ViewSupersession::Everything);

    // delta-1 suppressed in clean; the void-edge bookkeeping event remains.
    expect($clean)->toBe(['start-1', collect($everything)->last()->toArray()['id']])
        ->and(foldedIds($everything))->toContain('delta-1');

    $voided = collect($everything)->first(fn ($row) => $row instanceof VoidedEvent);
    expect($voided)->not->toBeNull()
        ->and($voided->event->toArray()['id'])->toBe('delta-1')
        ->and($voided->voidType)->toBe(CausalVoidEdgeType::Supersedes)
        ->and($voided->reason)->toBe('first draft revised');
});

test('on a real #282-only log presentation order equals causal order (no node structure)', function () {
    $store = app(CausalLogStore::class);
    seedFoldRun('run-fold-2');

    foreach (['e1', 'e2', 'e3'] as $i => $id) {
        $store->record('run-fold-2', new SwarmTextDelta(
            id: $id,
            runId: 'run-fold-2',
            stepIndex: 0,
            agentClass: 'ExampleAgent',
            delta: "chunk-{$i}",
            timestamp: SwarmStreamEvent::timestamp(),
        ), 0);
    }

    $view = CausalLogView::forRun($store, 'run-fold-2');

    $causal = foldedIds($view->fold(ViewOrder::Causal, ViewSupersession::Everything));
    $presentation = foldedIds($view->fold(ViewOrder::Presentation, ViewSupersession::Everything));

    expect($presentation)->toBe($causal)
        ->and($causal)->toBe(['e1', 'e2', 'e3']);
});

test('on a real #282-only log abandons suppresses only the single target', function () {
    $store = app(CausalLogStore::class);
    seedFoldRun('run-fold-3');

    foreach (['a', 'b', 'c'] as $id) {
        $store->record('run-fold-3', new SwarmTextDelta(
            id: $id,
            runId: 'run-fold-3',
            stepIndex: 0,
            agentClass: 'ExampleAgent',
            delta: $id,
            timestamp: SwarmStreamEvent::timestamp(),
        ), 0);
    }

    $store->appendVoidEdge('run-fold-3', CausalVoidEdgeType::Abandons, 'b', 'cancelled');

    $clean = foldedIds(CausalLogView::forRun($store, 'run-fold-3')->fold(ViewOrder::Causal, ViewSupersession::Clean));

    // No node_id on #282-era events, so only the single target 'b' is dropped.
    expect($clean)->not->toContain('b')
        ->and($clean)->toContain('a')
        ->and($clean)->toContain('c');
});

test('the same persisted log yields every view without mutating the store', function () {
    $store = app(CausalLogStore::class);
    seedFoldRun('run-fold-4');

    $store->record('run-fold-4', new SwarmTextDelta(
        id: 'only-1',
        runId: 'run-fold-4',
        stepIndex: 0,
        agentClass: 'ExampleAgent',
        delta: 'x',
        timestamp: SwarmStreamEvent::timestamp(),
    ), 0);

    $store->appendVoidEdge('run-fold-4', CausalVoidEdgeType::Supersedes, 'only-1', 'revised');

    $before = DB::table('swarm_stream_events')->where('run_id', 'run-fold-4')->count();

    $view = CausalLogView::forRun($store, 'run-fold-4');
    $view->fold(ViewOrder::Causal, ViewSupersession::Clean);
    $view->fold(ViewOrder::Presentation, ViewSupersession::Everything);

    $after = DB::table('swarm_stream_events')->where('run_id', 'run-fold-4')->count();

    // Reading never writes: row count is unchanged after every fold.
    expect($after)->toBe($before);
});
