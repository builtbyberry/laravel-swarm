<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Persistence\TieredStreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    config()->set('swarm.persistence.driver', 'database');
    app()->forgetInstance(TieredStreamEventStore::class);
    app()->forgetInstance(DatabaseColdArchiveDriver::class);
});

test('events() short-circuits to hot-only when no cold data exists', function () {
    $runId = 'run-hot-only';
    $now = now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'TestSwarm',
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

    /** @var TieredStreamEventStore $store */
    $store = app(TieredStreamEventStore::class);

    $store->hot->record($runId, new SwarmStreamStart(
        id: 'evt-hot-1',
        runId: $runId,
        swarmClass: 'TestSwarm',
        topology: 'sequential',
        input: 'only hot',
        metadata: [],
        timestamp: SwarmStreamEvent::timestamp(),
    ), 0);

    $eventsList = [];
    foreach ($store->events($runId) as $e) {
        $eventsList[] = $e;
    }
    $events = collect($eventsList);

    expect($events)->toHaveCount(1)
        ->and($events->first())->toBeInstanceOf(SwarmStreamStart::class)
        ->and($events->first()->input)->toBe('only hot');
});

test('events() stitches cold below base and hot at-or-above base with no gap or duplicate at the seam boundary', function () {
    $runId = 'run-seam-1';
    $now = now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'TestSwarm',
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

    /** @var TieredStreamEventStore $store */
    $store = app(TieredStreamEventStore::class);

    // Insert two events into the hot store; they receive auto-increment ids 1 and 2.
    $eventA = new SwarmStreamStart(
        id: 'evt-a',
        runId: $runId,
        swarmClass: 'TestSwarm',
        topology: 'sequential',
        input: 'cold event',
        metadata: [],
        timestamp: SwarmStreamEvent::timestamp(),
    );
    $eventB = new SwarmStreamStart(
        id: 'evt-b',
        runId: $runId,
        swarmClass: 'TestSwarm',
        topology: 'sequential',
        input: 'hot event at seam',
        metadata: [],
        timestamp: SwarmStreamEvent::timestamp(),
    );

    $store->hot->record($runId, $eventA, 0); // hot id = 1
    $store->hot->record($runId, $eventB, 0); // hot id = 2

    // Retrieve EventA's serialized payload from hot so the cold row round-trips cleanly.
    $coldPayload = DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->orderBy('id')
        ->value('payload');

    // Simulate compaction: graduate EventA (id=1) to cold as sequence=1.
    // Set base_pointer=2 — cold owns id < 2 (EventA), hot owns id >= 2 (EventB).
    DB::table('swarm_cold_archives')->insert([
        'run_id' => $runId,
        'archive_type' => 'event',
        'sequence' => 1,
        'payload' => $coldPayload,
        'base_pointer' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('swarm_cold_archives')->insert([
        'run_id' => $runId,
        'archive_type' => 'snapshot',
        'sequence' => null,
        'payload' => '{}',
        'base_pointer' => 2,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Collect via explicit iteration (events() is a generator; foreach is the safe consumer).
    $eventsList = [];
    foreach ($store->events($runId) as $e) {
        $eventsList[] = $e;
    }
    $events = collect($eventsList);

    // Exactly 2 events: EventA from cold (seq < 2) + EventB from hot (id >= 2).
    // EventA at id=1 in hot is excluded by eventsFrom(runId, 2) — no duplicate.
    // EventB at id=2 is the seam boundary event (id == base_pointer) and must appear.
    expect($events)->toHaveCount(2);
    expect($events->first())->toBeInstanceOf(SwarmStreamStart::class);
    expect($events->first()->input)->toBe('cold event');
    expect($events->last())->toBeInstanceOf(SwarmStreamStart::class);
    expect($events->last()->input)->toBe('hot event at seam');
});

test('assertReady() throws SwarmException when the cold archive table is missing', function () {
    Schema::connection('testing')->dropIfExists('swarm_cold_archives');

    $store = app(TieredStreamEventStore::class);

    expect(fn () => $store->assertReady())
        ->toThrow(SwarmException::class, 'swarm_cold_archives');
});

test('assertReady() throws SwarmException when required columns are missing from the cold archive table', function () {
    Schema::connection('testing')->table('swarm_cold_archives', function ($table): void {
        $table->dropColumn('base_pointer');
    });

    $store = app(TieredStreamEventStore::class);

    expect(fn () => $store->assertReady())
        ->toThrow(SwarmException::class, 'runtime columns');
});

test('forget() propagates deletion to both hot and cold tiers', function () {
    $runId = 'run-forget-cascade';
    $now = now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'TestSwarm',
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

    /** @var TieredStreamEventStore $store */
    $store = app(TieredStreamEventStore::class);

    $store->hot->record($runId, new SwarmStreamStart(
        id: 'evt-forget-1',
        runId: $runId,
        swarmClass: 'TestSwarm',
        topology: 'sequential',
        input: 'to be forgotten',
        metadata: [],
        timestamp: SwarmStreamEvent::timestamp(),
    ), 0);

    DB::table('swarm_cold_archives')->insert([
        ['run_id' => $runId, 'archive_type' => 'event', 'sequence' => 1, 'payload' => '{}', 'base_pointer' => null, 'created_at' => $now, 'updated_at' => $now],
        ['run_id' => $runId, 'archive_type' => 'snapshot', 'sequence' => null, 'payload' => '{}', 'base_pointer' => 2, 'created_at' => $now, 'updated_at' => $now],
    ]);

    expect(DB::table('swarm_stream_events')->where('run_id', $runId)->count())->toBe(1);
    expect(DB::table('swarm_cold_archives')->where('run_id', $runId)->count())->toBe(2);

    $store->forget($runId);

    expect(DB::table('swarm_stream_events')->where('run_id', $runId)->count())->toBe(0);
    expect(DB::table('swarm_cold_archives')->where('run_id', $runId)->count())->toBe(0);
});

test('DatabaseColdArchiveDriver::readSnapshotStrict() wraps DecryptException into SwarmException on a bad-key snapshot', function () {
    $runId = 'run-decrypt-fail';
    $now = now('UTC');

    // Insert a snapshot row with a sealed-looking payload that is not valid ciphertext.
    // The sw0: prefix triggers openStrict()'s decrypt path; the garbage body throws DecryptException.
    DB::table('swarm_cold_archives')->insert([
        'run_id' => $runId,
        'archive_type' => 'snapshot',
        'sequence' => null,
        'payload' => 'sw0:thisisnotvalidciphertextabcdef',
        'base_pointer' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $driver = app(DatabaseColdArchiveDriver::class);
    $cipher = app(SwarmPersistenceCipher::class);

    expect(fn () => $driver->readSnapshotStrict($runId, $cipher))
        ->toThrow(SwarmException::class, 'could not be decrypted');
});
