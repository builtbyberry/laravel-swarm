<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Persistence\TieredStreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalVoidEdge;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

/**
 * Seed the parent swarm_run_histories row the stream-event FK requires.
 */
function seedCausalFeatureRun(string $runId): void
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

test('under the database driver StreamEventStore resolves TieredStreamEventStore wrapping the shared DatabaseCausalLogStore', function () {
    config()->set('swarm.persistence.driver', 'database');
    app()->forgetInstance(StreamEventStore::class);
    app()->forgetInstance(CausalLogStore::class);
    app()->forgetInstance(DatabaseCausalLogStore::class);
    app()->forgetInstance(TieredStreamEventStore::class);

    $streamStore = app(StreamEventStore::class);
    $causalStore = app(CausalLogStore::class);

    // StreamEventStore now resolves the tiered decorator (#286).
    expect($streamStore)->toBeInstanceOf(TieredStreamEventStore::class);
    // CausalLogStore still resolves the shared DatabaseCausalLogStore directly.
    expect($causalStore)->toBeInstanceOf(DatabaseCausalLogStore::class);
    // The tiered store's hot inner store is the same singleton as CausalLogStore.
    expect($streamStore->hot)->toBe($causalStore);
});

test('a stream event survives a full DB round-trip through the causal log subclass', function () {
    $store = app(CausalLogStore::class);
    seedCausalFeatureRun('run-rt-1');

    $store->record('run-rt-1', new SwarmStreamStart(
        id: 'evt-rt-1',
        runId: 'run-rt-1',
        swarmClass: 'ExampleSwarm',
        topology: 'sequential',
        input: 'the prompt',
        metadata: ['k' => 'v'],
        timestamp: SwarmStreamEvent::timestamp(),
    ), 0);

    $store->appendVoidEdge('run-rt-1', CausalVoidEdgeType::Abandons, 'evt-rt-1', 'cancelled by operator');

    $events = collect($store->events('run-rt-1'));

    expect($events)->toHaveCount(2)
        ->and($events->first())->toBeInstanceOf(SwarmStreamStart::class)
        ->and($events->first()->input)->toBe('the prompt')
        ->and($events->last())->toBeInstanceOf(SwarmCausalVoidEdge::class)
        ->and($events->last()->voidType)->toBe(CausalVoidEdgeType::Abandons)
        ->and($events->last()->reason)->toBe('cancelled by operator');
});
