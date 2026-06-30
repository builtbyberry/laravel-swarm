<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SealedCausalWindowException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnknownCausalTargetException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalVoidEdge;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

/**
 * Seed the parent swarm_run_histories row the stream-event FK requires.
 */
function seedCausalLogRun(string $runId): void
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
 * Record a base event through the store and return its event UUID.
 */
function recordBaseEvent(CausalLogStore $store, string $runId, string $id): string
{
    seedCausalLogRun($runId);

    $store->record($runId, new SwarmStreamStart(
        id: $id,
        runId: $runId,
        swarmClass: 'ExampleSwarm',
        topology: 'sequential',
        input: 'hello',
        metadata: [],
        timestamp: SwarmStreamEvent::timestamp(),
    ), 0);

    return $id;
}

test('the container resolves CausalLogStore to the database causal log store', function () {
    expect(app(CausalLogStore::class))->toBeInstanceOf(DatabaseCausalLogStore::class);
});

test('record promotes the event id to the queryable event_uuid column', function () {
    $store = app(CausalLogStore::class);
    recordBaseEvent($store, 'run-record-1', 'event-aaa');

    expect(DB::table('swarm_stream_events')->where('event_uuid', 'event-aaa')->exists())->toBeTrue();
});

test('appendVoidEdge stores the void columns and a typed void-edge event', function () {
    $store = app(CausalLogStore::class);
    $target = recordBaseEvent($store, 'run-void-1', 'event-target');

    $store->appendVoidEdge('run-void-1', CausalVoidEdgeType::Supersedes, $target, 'coordinator re-routed');

    $edge = DB::table('swarm_stream_events')
        ->where('event_type', 'swarm_causal_void_edge')
        ->first();

    expect($edge->void_type)->toBe('supersedes')
        ->and($edge->void_target_event_uuid)->toBe('event-target')
        ->and($edge->void_reason)->toBe('coordinator re-routed')
        ->and($edge->event_uuid)->not->toBeNull();
});

test('isSealed is false while the target row is unsealed', function () {
    $store = app(CausalLogStore::class);
    $target = recordBaseEvent($store, 'run-seal-1', 'event-unsealed');

    expect($store->isSealed('run-seal-1', $target))->toBeFalse();
});

test('isSealed is true once the target row carries a sealed_at (the #287 guard)', function () {
    $store = app(CausalLogStore::class);
    $target = recordBaseEvent($store, 'run-seal-2', 'event-sealed');

    DB::table('swarm_stream_events')
        ->where('event_uuid', $target)
        ->update(['sealed_at' => now('UTC')]);

    expect($store->isSealed('run-seal-2', $target))->toBeTrue();
});

test('appendVoidEdge throws when the target has been sealed', function () {
    $store = app(CausalLogStore::class);
    $target = recordBaseEvent($store, 'run-seal-3', 'event-sealed-target');

    DB::table('swarm_stream_events')
        ->where('event_uuid', $target)
        ->update(['sealed_at' => now('UTC')]);

    $store->appendVoidEdge('run-seal-3', CausalVoidEdgeType::Supersedes, $target, 'too late');
})->throws(SealedCausalWindowException::class);

test('appendVoidEdge throws when the target does not exist in the run', function () {
    $store = app(CausalLogStore::class);

    $store->appendVoidEdge('run-unknown-1', CausalVoidEdgeType::Replaces, 'event-ghost', 'no such event');
})->throws(UnknownCausalTargetException::class);

test('a sealed target throws and never appends a dangling void-edge', function () {
    $store = app(CausalLogStore::class);
    $target = recordBaseEvent($store, 'run-seal-4', 'event-sealed-atomic');

    DB::table('swarm_stream_events')
        ->where('event_uuid', $target)
        ->update(['sealed_at' => now('UTC')]);

    try {
        $store->appendVoidEdge('run-seal-4', CausalVoidEdgeType::Abandons, $target, 'rejected');
    } catch (SealedCausalWindowException) {
        // expected
    }

    expect(DB::table('swarm_stream_events')->where('event_type', 'swarm_causal_void_edge')->count())->toBe(0);
});

test('events returns regular events and void-edge events together in causal order', function () {
    $store = app(CausalLogStore::class);
    $target = recordBaseEvent($store, 'run-order-1', 'event-first');
    $store->appendVoidEdge('run-order-1', CausalVoidEdgeType::Supersedes, $target, 'revised');

    $events = collect($store->events('run-order-1'));

    expect($events)->toHaveCount(2)
        ->and($events->first())->toBeInstanceOf(SwarmStreamStart::class)
        ->and($events->last())->toBeInstanceOf(SwarmCausalVoidEdge::class)
        ->and($events->last()->voidType)->toBe(CausalVoidEdgeType::Supersedes)
        ->and($events->last()->targetEventId)->toBe('event-first');
});

test('SwarmStreamEvent::fromArray deserializes a void-edge payload without throwing', function () {
    $edge = new SwarmCausalVoidEdge(
        id: 'edge-1',
        runId: 'run-x',
        voidType: CausalVoidEdgeType::Replaces,
        targetEventId: 'event-y',
        reason: 'retry',
        timestamp: SwarmStreamEvent::timestamp(),
    );

    $restored = SwarmStreamEvent::fromArray($edge->toArray());

    expect($restored)->toBeInstanceOf(SwarmCausalVoidEdge::class)
        ->and($restored->voidType)->toBe(CausalVoidEdgeType::Replaces)
        ->and($restored->targetEventId)->toBe('event-y')
        ->and($restored->reason)->toBe('retry');
});

/**
 * Record a durable node-attempt event (node_id + attempt_epoch stamped, exactly as
 * the durable sink does) and return its event UUID. The caller seeds the run.
 */
function recordNodeAttemptEvent(CausalLogStore $store, string $runId, string $id, string $nodeId, int $epoch): string
{
    $event = new SwarmTextDelta(
        id: $id,
        runId: $runId,
        stepIndex: 0,
        agentClass: 'ExampleAgent',
        delta: 'x',
        timestamp: SwarmStreamEvent::timestamp(),
    );
    $event->withNodeId($nodeId)->withAttemptEpoch($epoch);
    $store->record($runId, $event, 0);

    return $id;
}

test('latestAttemptEpochBelow returns null when the node has no earlier attempt (#298)', function () {
    $store = app(CausalLogStore::class);
    seedCausalLogRun('run-epoch-1');
    recordNodeAttemptEvent($store, 'run-epoch-1', 'evt-a', 'step:0', 2);

    // No epoch strictly below 2 for this node, and a different node is ignored.
    recordNodeAttemptEvent($store, 'run-epoch-1', 'evt-b', 'step:1', 0);

    expect($store->latestAttemptEpochBelow('run-epoch-1', 'step:0', 2))->toBeNull();
});

test('latestAttemptEpochBelow returns the highest epoch strictly below the given epoch (#298)', function () {
    $store = app(CausalLogStore::class);
    seedCausalLogRun('run-epoch-2');
    recordNodeAttemptEvent($store, 'run-epoch-2', 'evt-e0', 'step:0', 0);
    recordNodeAttemptEvent($store, 'run-epoch-2', 'evt-e1', 'step:0', 3);
    recordNodeAttemptEvent($store, 'run-epoch-2', 'evt-e2', 'step:0', 5);

    expect($store->latestAttemptEpochBelow('run-epoch-2', 'step:0', 5))->toBe(3)
        ->and($store->latestAttemptEpochBelow('run-epoch-2', 'step:0', 3))->toBe(0)
        ->and($store->latestAttemptEpochBelow('run-epoch-2', 'step:0', 1))->toBe(0);
});

test('voidNodeAttempt retracts the prior attempt with one node_reexecuted edge against its first event (#298)', function () {
    $store = app(CausalLogStore::class);
    seedCausalLogRun('run-void-attempt-1');
    $first = recordNodeAttemptEvent($store, 'run-void-attempt-1', 'evt-first', 'step:0', 0);
    recordNodeAttemptEvent($store, 'run-void-attempt-1', 'evt-second', 'step:0', 0);

    $store->voidNodeAttempt('run-void-attempt-1', 'step:0', 0, 'durable node re-executed on resume');

    $edge = DB::table('swarm_stream_events')
        ->where('run_id', 'run-void-attempt-1')
        ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)
        ->first();

    expect($edge)->not->toBeNull()
        ->and($edge->void_target_event_uuid)->toBe($first);
});

test('voidNodeAttempt is idempotent — a repeated resume never double-voids (#298 F3)', function () {
    $store = app(CausalLogStore::class);
    seedCausalLogRun('run-void-attempt-2');
    recordNodeAttemptEvent($store, 'run-void-attempt-2', 'evt-only', 'step:0', 0);

    $store->voidNodeAttempt('run-void-attempt-2', 'step:0', 0, 'resume');
    $store->voidNodeAttempt('run-void-attempt-2', 'step:0', 0, 'redelivered resume');

    expect(
        DB::table('swarm_stream_events')
            ->where('run_id', 'run-void-attempt-2')
            ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)
            ->count()
    )->toBe(1);
});

test('voidNodeAttempt is a no-op when the prior attempt streamed nothing (#298)', function () {
    $store = app(CausalLogStore::class);
    seedCausalLogRun('run-void-attempt-3');

    $store->voidNodeAttempt('run-void-attempt-3', 'step:0', 0, 'crash before first event');

    expect(
        DB::table('swarm_stream_events')
            ->where('run_id', 'run-void-attempt-3')
            ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)
            ->count()
    )->toBe(0);
});
