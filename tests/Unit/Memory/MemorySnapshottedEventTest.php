<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Events\Memory\MemorySnapshotted;
use Illuminate\Support\Facades\Event;

/**
 * Shape-only tests for the MemorySnapshotted event.
 *
 * The snapshot mechanism itself ships with issue #111 — this v0.9.0 commit
 * only defines the event so app-level listeners can be authored against a
 * stable payload before the dispatching code lands.
 */
test('MemorySnapshotted exposes runId, stepIndex, and snapshotId as readonly properties', function () {
    $event = new MemorySnapshotted(
        runId: 'run-42',
        stepIndex: 3,
        snapshotId: 'snap-abc',
    );

    expect($event->runId)->toBe('run-42');
    expect($event->stepIndex)->toBe(3);
    expect($event->snapshotId)->toBe('snap-abc');
});

test('MemorySnapshotted can be dispatched through the Laravel event bus', function () {
    Event::fake([MemorySnapshotted::class]);

    Event::dispatch(new MemorySnapshotted('run-1', 0, 'snap-1'));

    Event::assertDispatched(MemorySnapshotted::class, fn (MemorySnapshotted $e): bool => $e->runId === 'run-1'
        && $e->stepIndex === 0
        && $e->snapshotId === 'snap-1');
});
