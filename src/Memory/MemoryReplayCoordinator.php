<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Attributes\MemoryReplay;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;

/**
 * Wraps a durable agent invocation with the snapshot-backed memory lifecycle.
 *
 * For each call to {@see during()}, the coordinator:
 *
 *   1. Reads the `#[MemoryReplay]` attribute on the swarm class (falls back to
 *      `swarm.memory.replay_mode` config). If the resolved mode is
 *      {@see ReplayMode::FreshExecution} the callback is invoked immediately
 *      with no binding swap.
 *   2. Calls {@see SnapshotsMemory::find()} to detect a prior crashed attempt.
 *      If none is found this is a fresh execution — the callback is invoked with
 *      `null` and the runner's normal `snapshot()` call proceeds as usual.
 *   3. On replay: swaps the container's `SwarmMemory` binding to a
 *      {@see ReplaySwarmMemory} decorator backed by the existing frozen snapshot.
 *      The callback is invoked with the existing snapshot so callers that manage
 *      their own snapshot lifecycle (e.g. `DurableBranchAdvancer`) can skip the
 *      redundant `find()` and decide between `resetToolCalls()` vs `snapshot()`.
 *   4. Restores the original `SwarmMemory` binding in a `finally` block
 *      regardless of whether the callback throws.
 *
 * Because `DatabaseMemorySnapshotRecorder::snapshot()` reads from
 * `SwarmMemory::all(Run, $runId)`, swapping the binding before any runner
 * method that internally calls `snapshot()` causes that UPSERT to re-write the
 * row with the frozen entries instead of live memory — the net result is
 * identical snapshot contents with an empty `tool_calls` column ready for
 * rebuilding by the new attempt.
 *
 * @internal
 */
final class MemoryReplayCoordinator
{
    public function __construct(
        protected SnapshotsMemory $snapshots,
        protected Application $application,
        protected Dispatcher $events,
    ) {}

    /**
     * Execute `$callback` inside a snapshot-backed memory context for the given
     * run step, restoring the original binding in `finally`.
     *
     * The callback receives `?MemorySnapshot`: `null` means fresh execution
     * (no prior crashed attempt found), non-null means replay (the binding has
     * been swapped and the snapshot is available for tool-call lifecycle).
     *
     * @template T
     *
     * @param  class-string  $swarmClass
     * @param  Closure(?MemorySnapshot): T  $callback
     * @return T
     */
    public function during(string $swarmClass, string $runId, int $stepIndex, Closure $callback): mixed
    {
        if (! $this->replayEnabled($swarmClass)) {
            return $callback(null);
        }

        $existing = $this->snapshots->find($runId, $stepIndex);

        if ($existing === null) {
            return $callback(null);
        }

        /** @var SwarmMemory $original */
        $original = $this->application->make(SwarmMemory::class);

        $this->application->instance(
            SwarmMemory::class,
            new ReplaySwarmMemory(
                live: $original,
                snapshot: $existing,
                events: $this->events,
            ),
        );

        try {
            return $callback($existing);
        } finally {
            $this->application->instance(SwarmMemory::class, $original);
        }
    }

    /**
     * Resolve the effective replay mode for `$swarmClass`.
     *
     * The `#[MemoryReplay]` attribute wins over the global config when present.
     *
     * @param  class-string  $swarmClass
     */
    protected function replayEnabled(string $swarmClass): bool
    {
        $ref = new ReflectionClass($swarmClass);
        $attrs = $ref->getAttributes(MemoryReplay::class);

        $mode = $attrs !== []
            ? $attrs[0]->newInstance()->mode
            : ReplayMode::from(config('swarm.memory.replay_mode', ReplayMode::FrozenView->value));

        return $mode === ReplayMode::FrozenView;
    }
}
