<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Attributes\MemoryReplay;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tools\Remember;
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
 *   3. On replay: installs a {@see ReplaySwarmMemory} decorator (backed by the
 *      existing frozen snapshot) as a per-invocation override on the active
 *      {@see ActiveRunContext} frame. The callback is invoked with the existing
 *      snapshot so callers that manage their own snapshot lifecycle (e.g.
 *      `DurableBranchAdvancer`) can skip the redundant `find()` and decide
 *      between `resetToolCalls()` vs `snapshot()`.
 *   4. Clears the override in a `finally` block regardless of whether the
 *      callback throws.
 *
 * The override is scoped to the run's `ActiveRunContext` frame — process-local,
 * per-invocation, and flushed on every Octane worker reset — rather than rebound
 * on the container. That is what makes concurrent in-process streaming safe: two
 * runs sharing one container each read their own frozen view, with no cross-run
 * bleed and no risk of restoring the wrong "original" binding. Both the read
 * chokepoint ({@see AgentVisibleMemoryView}) and the write chokepoint
 * ({@see Remember}) prefer
 * {@see ActiveRunContext::currentMemory()} over the live store, so the frozen
 * view reaches the agent without any global mutation.
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
     * The durable advancers enter their own {@see ActiveRunContext} frame
     * *inside* `$callback` (for the agent invocation), so at this point there is
     * typically no frame yet to carry the per-invocation override. When
     * `$context` is provided this method pushes a dedicated override-bearing
     * frame that brackets the callback; the inner frame the callback adds
     * inherits the override via {@see ActiveRunContext::currentMemory()}'s
     * top-down walk, and exit() in the finally tears it down even if the
     * callback threw. When `$context` is null the override is set on the
     * existing top frame instead (idempotently cleared in the finally).
     *
     * @template T
     *
     * @param  class-string  $swarmClass
     * @param  Closure(?MemorySnapshot): T  $callback
     * @return T
     */
    public function during(string $swarmClass, string $runId, int $stepIndex, Closure $callback, ?RunContext $context = null): mixed
    {
        if (! $this->replayEnabled($swarmClass)) {
            return $callback(null);
        }

        $existing = $this->snapshots->find($runId, $stepIndex);

        if ($existing === null) {
            return $callback(null);
        }

        // Resolve the concrete live store, NOT the SwarmMemory contract: the
        // contract binding prefers the active frame override, so resolving it here
        // could wrap an override inside another ReplaySwarmMemory.
        /** @var SwarmMemory $live */
        $live = $this->application->make(DefaultSwarmMemory::class);

        $replay = new ReplaySwarmMemory(
            live: $live,
            snapshot: $existing,
            events: $this->events,
        );

        if ($context !== null) {
            ActiveRunContext::enter($runId, $swarmClass, $context, $replay);

            try {
                return $callback($existing);
            } finally {
                ActiveRunContext::exit();
            }
        }

        ActiveRunContext::withMemoryOverride($replay);

        try {
            return $callback($existing);
        } finally {
            ActiveRunContext::clearMemoryOverride();
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
     * - Returns a fresh-execution boundary when replay is disabled for
     *   `$swarmClass` or no prior crashed attempt exists — the caller takes the
     *   fresh-execution path and freezes a new snapshot as usual. No override is
     *   installed.
     * - Returns a replay boundary carrying the existing frozen
     *   {@see MemorySnapshot} when a prior attempt is found, after installing a
     *   {@see ReplaySwarmMemory} backed by that snapshot as the per-invocation
     *   override on the active {@see ActiveRunContext} frame. The caller MUST
     *   have already entered that frame (streaming runners enter once for the
     *   generator's lifetime) and MUST pass the returned {@see ReplayBoundary}
     *   to {@see end()} in a `finally` block so the override is cleared even when
     *   the generator is torn down mid-stream.
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

        // Resolve the concrete live store, NOT the SwarmMemory contract: the
        // contract binding prefers the active frame override, so resolving it here
        // could wrap an override inside another ReplaySwarmMemory.
        /** @var SwarmMemory $live */
        $live = $this->application->make(DefaultSwarmMemory::class);

        ActiveRunContext::withMemoryOverride(new ReplaySwarmMemory(
            live: $live,
            snapshot: $existing,
            events: $this->events,
        ));

        return ReplayBoundary::replay($existing);
    }

    /**
     * Close a replay boundary opened by {@see begin()}, clearing the
     * per-invocation frozen-memory override from the active frame. A no-op for
     * fresh-execution boundaries. Idempotent — safe to call more than once.
     */
    public function end(ReplayBoundary $boundary): void
    {
        if (! $boundary->isReplay()) {
            return;
        }

        ActiveRunContext::clearMemoryOverride();
    }

    /**
     * Detect a prior frozen snapshot for `(runId, stepIndex)` WITHOUT installing
     * any frame override.
     *
     * Returns the existing {@see MemorySnapshot} when replay is enabled and a
     * prior crashed attempt exists, else null. Used by the static-hierarchical
     * concurrent-branch path, where the parent must persist each branch's
     * snapshot before `ConcurrencyManager` dispatches the (possibly forked)
     * children — but the parent itself reads no memory through the frame, so it
     * must not mutate it. The child callback installs its own override by calling
     * {@see begin()} after it enters its own {@see ActiveRunContext} frame in its
     * own process.
     *
     * @param  class-string  $swarmClass
     */
    public function existingSnapshot(string $swarmClass, string $runId, int $stepIndex): ?MemorySnapshot
    {
        if (! $this->replayEnabled($swarmClass)) {
            return null;
        }

        return $this->snapshots->find($runId, $stepIndex);
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
