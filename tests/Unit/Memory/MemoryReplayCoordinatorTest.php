<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Attributes\MemoryReplay;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemoryReplayCoordinator;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Memory\ReplaySwarmMemory;
use BuiltByBerry\LaravelSwarm\Tests\Support\InMemoryMemoryStore;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use Illuminate\Events\Dispatcher;

/**
 * Unit tests for {@see MemoryReplayCoordinator}.
 *
 * Verifies the three guarantees the coordinator provides:
 *
 * 1. Fresh execution (no existing snapshot) — callback is invoked with `null`,
 *    no container binding swap occurs.
 * 2. Replay (existing snapshot found) — container SwarmMemory is swapped to a
 *    ReplaySwarmMemory for the callback duration, then restored in finally.
 * 3. FreshExecution mode (via attribute or config) — callback is invoked with
 *    `null` regardless of whether a snapshot exists.
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeCoordinator(RecordingSnapshotsMemory $snapshots): MemoryReplayCoordinator
{
    return new MemoryReplayCoordinator(
        snapshots: $snapshots,
        application: app(),
        events: app(Dispatcher::class),
    );
}

/**
 * @param  array<int, array{scope: string, scope_id: string, key: string, value: mixed, metadata: array<string, mixed>}>  $entries
 */
function preloadSnapshot(RecordingSnapshotsMemory $snapshots, string $runId, int $stepIndex, array $entries = []): MemorySnapshot
{
    // fromPersisted() requires created_at/updated_at in each entry row.
    $normalized = array_map(
        fn (array $e): array => $e + ['created_at' => null, 'updated_at' => null],
        $entries,
    );

    $snapshot = MemorySnapshot::fromPersisted(
        ['run_id' => $runId, 'step_index' => $stepIndex, 'entries' => $normalized],
        [],
    );
    $snapshots->preload($snapshot);

    return $snapshot;
}

beforeEach(function () {
    // Bind a live SwarmMemory into the container so the coordinator can capture
    // and restore it.
    $this->app->singleton(SwarmMemory::class, fn () => new DefaultSwarmMemory(new InMemoryMemoryStore));
    $this->liveMemory = $this->app->make(SwarmMemory::class);
});

// ---------------------------------------------------------------------------
// Fresh execution (no existing snapshot)
// ---------------------------------------------------------------------------

test('callback is called with null when no existing snapshot is found', function () {
    $snapshots = new RecordingSnapshotsMemory;
    $coordinator = makeCoordinator($snapshots);

    $received = 'not-set';
    $coordinator->during('stdClass', 'run-1', 0, function (?MemorySnapshot $s) use (&$received) {
        $received = $s;
    });

    expect($received)->toBeNull();
});

test('SwarmMemory binding is unchanged on fresh execution', function () {
    $snapshots = new RecordingSnapshotsMemory;
    $coordinator = makeCoordinator($snapshots);

    $seenInCallback = null;
    $coordinator->during('stdClass', 'run-1', 0, function () use (&$seenInCallback) {
        $seenInCallback = app(SwarmMemory::class);
    });

    expect($seenInCallback)->toBeInstanceOf(DefaultSwarmMemory::class);
});

test('coordinator returns the callback return value on fresh execution', function () {
    $snapshots = new RecordingSnapshotsMemory;
    $coordinator = makeCoordinator($snapshots);

    $result = $coordinator->during('stdClass', 'run-1', 0, fn () => 'fresh-result');

    expect($result)->toBe('fresh-result');
});

// ---------------------------------------------------------------------------
// Replay — binding swap
// ---------------------------------------------------------------------------

test('callback is called with existing snapshot when a prior attempt is found', function () {
    $snapshots = new RecordingSnapshotsMemory;
    $frozen = preloadSnapshot($snapshots, 'run-1', 3);
    $coordinator = makeCoordinator($snapshots);

    $received = 'not-set';
    $coordinator->during('stdClass', 'run-1', 3, function (?MemorySnapshot $s) use (&$received) {
        $received = $s;
    });

    expect($received)->toBe($frozen);
});

test('SwarmMemory binding is swapped to ReplaySwarmMemory during the callback on replay', function () {
    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0, [
        ['scope' => 'run', 'scope_id' => 'run-1', 'key' => 'frozen_key', 'value' => 'frozen_value', 'metadata' => []],
    ]);
    $coordinator = makeCoordinator($snapshots);

    $seenInCallback = null;
    $coordinator->during('stdClass', 'run-1', 0, function () use (&$seenInCallback) {
        $seenInCallback = app(SwarmMemory::class);
    });

    expect($seenInCallback)->toBeInstanceOf(ReplaySwarmMemory::class);
});

test('ReplaySwarmMemory during callback serves frozen values, not live store', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    $this->app->instance(SwarmMemory::class, $live);

    // Drift live store past the snapshot.
    $live->put(MemoryScope::Run, 'run-1', 'key', 'live-drifted');

    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0, [
        ['scope' => 'run', 'scope_id' => 'run-1', 'key' => 'key', 'value' => 'frozen-value', 'metadata' => []],
    ]);
    $coordinator = makeCoordinator($snapshots);

    $seenValue = null;
    $coordinator->during('stdClass', 'run-1', 0, function () use (&$seenValue) {
        $seenValue = app(SwarmMemory::class)->get(MemoryScope::Run, 'run-1', 'key');
    });

    expect($seenValue)->toBe('frozen-value');
});

test('original SwarmMemory binding is restored after the callback completes', function () {
    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0);
    $coordinator = makeCoordinator($snapshots);

    $coordinator->during('stdClass', 'run-1', 0, fn () => null);

    expect(app(SwarmMemory::class))->toBeInstanceOf(DefaultSwarmMemory::class);
});

test('original SwarmMemory binding is restored even when the callback throws', function () {
    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0);
    $coordinator = makeCoordinator($snapshots);

    try {
        $coordinator->during('stdClass', 'run-1', 0, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    expect(app(SwarmMemory::class))->toBeInstanceOf(DefaultSwarmMemory::class);
});

test('coordinator returns the callback return value on replay', function () {
    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0);
    $coordinator = makeCoordinator($snapshots);

    $result = $coordinator->during('stdClass', 'run-1', 0, fn () => 'replay-result');

    expect($result)->toBe('replay-result');
});

// ---------------------------------------------------------------------------
// FreshExecution mode — via config
// ---------------------------------------------------------------------------

test('config fresh_execution bypasses the snapshot check and invokes callback with null', function () {
    config(['swarm.memory.replay_mode' => ReplayMode::FreshExecution->value]);

    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0); // a snapshot exists — should be ignored

    $coordinator = makeCoordinator($snapshots);

    $received = 'not-set';
    $coordinator->during('stdClass', 'run-1', 0, function (?MemorySnapshot $s) use (&$received) {
        $received = $s;
    });

    expect($received)->toBeNull();
});

test('config fresh_execution leaves the SwarmMemory binding untouched', function () {
    config(['swarm.memory.replay_mode' => ReplayMode::FreshExecution->value]);

    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0);

    $coordinator = makeCoordinator($snapshots);

    $seenInCallback = null;
    $coordinator->during('stdClass', 'run-1', 0, function () use (&$seenInCallback) {
        $seenInCallback = app(SwarmMemory::class);
    });

    expect($seenInCallback)->toBeInstanceOf(DefaultSwarmMemory::class);
});

// ---------------------------------------------------------------------------
// FreshExecution mode — via #[MemoryReplay] attribute (wins over config)
// ---------------------------------------------------------------------------

test('MemoryReplay attribute with FreshExecution wins over frozen_view config', function () {
    config(['swarm.memory.replay_mode' => ReplayMode::FrozenView->value]); // config says frozen

    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0);

    $coordinator = makeCoordinator($snapshots);

    // Anonymous class with the attribute.
    $swarmClass = new #[MemoryReplay(mode: ReplayMode::FreshExecution)] class {};

    $seenInCallback = null;
    $coordinator->during($swarmClass::class, 'run-1', 0, function () use (&$seenInCallback) {
        $seenInCallback = app(SwarmMemory::class);
    });

    // Attribute wins — no binding swap despite frozen_view config.
    expect($seenInCallback)->toBeInstanceOf(DefaultSwarmMemory::class);
});

test('MemoryReplay attribute with FrozenView wins over fresh_execution config', function () {
    config(['swarm.memory.replay_mode' => ReplayMode::FreshExecution->value]); // config says fresh

    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0);

    $coordinator = makeCoordinator($snapshots);

    $swarmClass = new #[MemoryReplay(mode: ReplayMode::FrozenView)] class {};

    $seenInCallback = null;
    $coordinator->during($swarmClass::class, 'run-1', 0, function () use (&$seenInCallback) {
        $seenInCallback = app(SwarmMemory::class);
    });

    // Attribute wins — binding IS swapped despite fresh_execution config.
    expect($seenInCallback)->toBeInstanceOf(ReplaySwarmMemory::class);
});
