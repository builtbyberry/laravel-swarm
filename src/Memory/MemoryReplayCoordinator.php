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
     * Begin a snapshot-backed replay boundary for a generator-based runner.
     *
     * The closure-based {@see during()} cannot wrap a runner that *yields*
     * across the boundary (a streamed agent invocation), so streaming runners
     * use this explicit begin/end pair instead. The semantics match
     * {@see during()} exactly:
     *
     * - Returns `null` when replay is disabled for `$swarmClass` or no prior
     *   crashed attempt exists — the caller takes the fresh-execution path and
     *   freezes a new snapshot as usual. No binding swap happens.
     * - Returns the existing frozen {@see MemorySnapshot} when a prior attempt
     *   is found, after swapping the container's `SwarmMemory` to a
     *   {@see ReplaySwarmMemory} backed by that snapshot. The caller MUST pass
     *   the returned {@see ReplayBoundary} to {@see end()} in a `finally` block
     *   so the original binding is restored even when the generator is torn
     *   down mid-stream.
     *
     * @param  class-string  $swarmClass
     */
    public function begin(string $swarmClass, string $runId, int $stepIndex): ReplayBoundary
    {
        if (! $this->replayEnabled($swarmClass)) {
            return ReplayBoundary::freshExecution();
        }

        $existing = $this->snapshots->find($runId, $stepIndex);

        if ($existing === null) {
            return ReplayBoundary::freshExecution();
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

        return ReplayBoundary::replay($existing, $original);
    }

    /**
     * Close a replay boundary opened by {@see begin()}, restoring the original
     * `SwarmMemory` binding. A no-op for fresh-execution boundaries. Safe to
     * call more than once.
     */
    public function end(ReplayBoundary $boundary): void
    {
        if ($boundary->original === null) {
            return;
        }

        $this->application->instance(SwarmMemory::class, $boundary->original);
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
