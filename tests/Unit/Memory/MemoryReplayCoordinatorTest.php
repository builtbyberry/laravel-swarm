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
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Support\InMemoryMemoryStore;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use Illuminate\Events\Dispatcher;

/**
 * Unit tests for {@see MemoryReplayCoordinator}.
 *
 * Verifies the three guarantees the coordinator provides under the
 * per-invocation frozen-view model (the container's SwarmMemory binding is never
 * mutated; the frozen view is carried on the {@see ActiveRunContext} frame):
 *
 * 1. Fresh execution (no existing snapshot) — callback is invoked with `null`,
 *    no override is installed.
 * 2. Replay (existing snapshot found) — the agent-visible memory resolves to a
 *    ReplaySwarmMemory for the callback duration via
 *    {@see ActiveRunContext::currentMemory()}, then is cleared in finally.
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

/**
 * Resolve the memory an agent invocation would read at this moment: the
 * per-invocation override when a replay is active, else the live binding.
 */
function effectiveMemory(): SwarmMemory
{
    return ActiveRunContext::currentMemory() ?? app(SwarmMemory::class);
}

beforeEach(function () {
    // Bind a live SwarmMemory into the container. The coordinator never mutates
    // this binding; it carries the frozen view on the ActiveRunContext frame.
    $this->app->singleton(SwarmMemory::class, fn () => new DefaultSwarmMemory(new InMemoryMemoryStore));
    $this->liveMemory = $this->app->make(SwarmMemory::class);
    ActiveRunContext::flush();
});

afterEach(function () {
    ActiveRunContext::flush();
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

test('agent-visible memory is the live store on fresh execution', function () {
    $snapshots = new RecordingSnapshotsMemory;
    $coordinator = makeCoordinator($snapshots);

    $seenInCallback = null;
    $coordinator->during('stdClass', 'run-1', 0, function () use (&$seenInCallback) {
        $seenInCallback = effectiveMemory();
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
// Replay — frozen-view override on the frame
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

test('agent-visible memory resolves to ReplaySwarmMemory during the callback on replay', function () {
    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0, [
        ['scope' => 'run', 'scope_id' => 'run-1', 'key' => 'frozen_key', 'value' => 'frozen_value', 'metadata' => []],
    ]);
    $coordinator = makeCoordinator($snapshots);

    $context = new RunContext('run-1', 'task');

    $seenInCallback = null;
    $coordinator->during('stdClass', 'run-1', 0, function () use (&$seenInCallback) {
        $seenInCallback = effectiveMemory();
    }, $context);

    expect($seenInCallback)->toBeInstanceOf(ReplaySwarmMemory::class);
    // The container binding itself is never swapped — no global residue.
    expect(app(SwarmMemory::class))->toBeInstanceOf(DefaultSwarmMemory::class);
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

    $context = new RunContext('run-1', 'task');

    $seenValue = null;
    $coordinator->during('stdClass', 'run-1', 0, function () use (&$seenValue) {
        $seenValue = effectiveMemory()->get(MemoryScope::Run, 'run-1', 'key');
    }, $context);

    expect($seenValue)->toBe('frozen-value');
});

test('override is cleared and no frame leaks after the callback completes', function () {
    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0);
    $coordinator = makeCoordinator($snapshots);

    $coordinator->during('stdClass', 'run-1', 0, fn () => null, new RunContext('run-1', 'task'));

    expect(ActiveRunContext::currentMemory())->toBeNull();
    expect(ActiveRunContext::current())->toBeNull();
    expect(app(SwarmMemory::class))->toBeInstanceOf(DefaultSwarmMemory::class);
});

test('override is cleared even when the callback throws', function () {
    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0);
    $coordinator = makeCoordinator($snapshots);

    try {
        $coordinator->during('stdClass', 'run-1', 0, fn () => throw new RuntimeException('boom'), new RunContext('run-1', 'task'));
    } catch (RuntimeException) {
        // expected
    }

    expect(ActiveRunContext::currentMemory())->toBeNull();
    expect(ActiveRunContext::current())->toBeNull();
    expect(app(SwarmMemory::class))->toBeInstanceOf(DefaultSwarmMemory::class);
});

test('coordinator returns the callback return value on replay', function () {
    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0);
    $coordinator = makeCoordinator($snapshots);

    $result = $coordinator->during('stdClass', 'run-1', 0, fn () => 'replay-result', new RunContext('run-1', 'task'));

    expect($result)->toBe('replay-result');
});

test('during sets the override on the existing top frame when no context is passed', function () {
    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0);
    $coordinator = makeCoordinator($snapshots);

    // Simulate a caller that has already entered its own frame before during().
    ActiveRunContext::enter('run-1', 'stdClass', new RunContext('run-1', 'task'));

    $seenInCallback = null;
    $coordinator->during('stdClass', 'run-1', 0, function () use (&$seenInCallback) {
        $seenInCallback = effectiveMemory();
    });

    // Override applied to the existing frame, then cleared — frame still present.
    expect($seenInCallback)->toBeInstanceOf(ReplaySwarmMemory::class);
    expect(ActiveRunContext::currentMemory())->toBeNull();
    expect(ActiveRunContext::current())->not->toBeNull();

    ActiveRunContext::exit();
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

test('config fresh_execution leaves the agent-visible memory as the live store', function () {
    config(['swarm.memory.replay_mode' => ReplayMode::FreshExecution->value]);

    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0);

    $coordinator = makeCoordinator($snapshots);

    $seenInCallback = null;
    $coordinator->during('stdClass', 'run-1', 0, function () use (&$seenInCallback) {
        $seenInCallback = effectiveMemory();
    }, new RunContext('run-1', 'task'));

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
        $seenInCallback = effectiveMemory();
    }, new RunContext('run-1', 'task'));

    // Attribute wins — no override installed despite frozen_view config.
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
        $seenInCallback = effectiveMemory();
    }, new RunContext('run-1', 'task'));

    // Attribute wins — frozen-view override IS installed despite fresh_execution config.
    expect($seenInCallback)->toBeInstanceOf(ReplaySwarmMemory::class);
});

// ---------------------------------------------------------------------------
// begin() / end() — the generator-friendly boundary used by streaming runners
// ---------------------------------------------------------------------------

test('begin returns a fresh-execution boundary when no snapshot exists', function () {
    $snapshots = new RecordingSnapshotsMemory;
    $coordinator = makeCoordinator($snapshots);

    ActiveRunContext::enter('run-1', 'stdClass', new RunContext('run-1', 'task'));

    $boundary = $coordinator->begin('stdClass', 'run-1', 0);

    expect($boundary->isReplay())->toBeFalse();
    expect($boundary->snapshot)->toBeNull();
    expect(ActiveRunContext::currentMemory())->toBeNull();

    // end() is a no-op for a fresh boundary.
    $coordinator->end($boundary);
    expect(ActiveRunContext::currentMemory())->toBeNull();

    ActiveRunContext::exit();
});

test('begin installs the override and returns a replay boundary when a snapshot exists', function () {
    $snapshots = new RecordingSnapshotsMemory;
    $frozen = preloadSnapshot($snapshots, 'run-1', 2);
    $coordinator = makeCoordinator($snapshots);

    // The streaming runner enters one frame for the generator's lifetime before
    // calling begin() per step.
    ActiveRunContext::enter('run-1', 'stdClass', new RunContext('run-1', 'task'));

    $boundary = $coordinator->begin('stdClass', 'run-1', 2);

    expect($boundary->isReplay())->toBeTrue();
    expect($boundary->snapshot)->toBe($frozen);
    // The override stays installed until end() — the generator yields under it.
    expect(ActiveRunContext::currentMemory())->toBeInstanceOf(ReplaySwarmMemory::class);
    // The container binding is never mutated.
    expect(app(SwarmMemory::class))->toBeInstanceOf(DefaultSwarmMemory::class);

    $coordinator->end($boundary);

    // Cleared after the boundary closes; the frame itself remains.
    expect(ActiveRunContext::currentMemory())->toBeNull();
    expect(ActiveRunContext::current())->not->toBeNull();

    ActiveRunContext::exit();
});

test('begin honours fresh_execution mode and never installs an override', function () {
    config(['swarm.memory.replay_mode' => ReplayMode::FreshExecution->value]);

    $snapshots = new RecordingSnapshotsMemory;
    preloadSnapshot($snapshots, 'run-1', 0); // a snapshot exists — should be ignored

    $coordinator = makeCoordinator($snapshots);

    ActiveRunContext::enter('run-1', 'stdClass', new RunContext('run-1', 'task'));

    $boundary = $coordinator->begin('stdClass', 'run-1', 0);

    expect($boundary->isReplay())->toBeFalse();
    expect(ActiveRunContext::currentMemory())->toBeNull();

    ActiveRunContext::exit();
});
