<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Concerns\RemembersRunContext;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Octane\Contracts\OperationTerminated;

/**
 * Process-local handle to the swarm run currently executing an agent.
 *
 * Runners call {@see enter()} immediately before invoking an agent and
 * {@see exit()} in a finally afterward, so code with no handle to the runner —
 * notably an agent's {@see Conversational::messages()} via
 * the {@see RemembersRunContext} trait — can
 * discover the active run id, swarm class, and live {@see RunContext}.
 *
 * Deliberately a plain in-process stack rather than {@see Context}:
 *
 *  - Every invocation site sets this explicitly (the cross-process worker
 *    closures re-establish it from the forwarded {@see RunContext} payload, and
 *    the durable advancer re-enters with its loaded context), so we never rely
 *    on automatic queue/process propagation.
 *  - It therefore never leaks the run's input or memory into log records or
 *    queued-job payloads (Context's visible bucket is injected into every log
 *    line by the framework's context log processor).
 *  - It stores the live RunContext by reference — no per-invocation
 *    serialization — so the view the trait renders matches the snapshot the
 *    runner froze for the same step.
 *
 * The stack makes nested runs (an agent that synchronously drives a sub-swarm)
 * safe: each {@see enter()}/{@see exit()} pair manages its own frame.
 *
 * Lifecycle: the stack is run-scoped and self-healing. Each invocation site
 * pairs {@see enter()} with {@see exit()} in a finally, so under normal flow
 * (including exceptions) the stack returns to empty. On a long-lived worker
 * (queue, Octane) an abnormal termination that bypasses the finally — a hard
 * timeout or fatal — can leave a stale frame, but it is never *read*:
 * {@see current()} returns the top, and the next run's {@see enter()} pushes a
 * fresh frame above it, so there is no correctness or cross-run-bleed concern.
 * The only residue is a single retained {@see RunContext} reference until the
 * worker recycles. Under Laravel Octane this residue is cleared eagerly: when
 * the package detects Octane is installed, {@see SwarmServiceProvider} wires an
 * {@see OperationTerminated} listener that calls
 * {@see flush()} on every worker reset (request, task, and tick). Non-Octane
 * apps rely on the self-healing shadowing described above.
 *
 * @internal
 */
final class ActiveRunContext
{
    /** @var list<ActiveRunRecord> */
    private static array $stack = [];

    public static function enter(string $runId, string $swarmClass, RunContext $context, ?SwarmMemory $memoryOverride = null): void
    {
        self::$stack[] = new ActiveRunRecord($runId, $swarmClass, $context, $memoryOverride);
    }

    public static function exit(): void
    {
        array_pop(self::$stack);
    }

    public static function current(): ?ActiveRunRecord
    {
        return self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
    }

    /**
     * Set the per-invocation frozen-memory override on the top frame. Used by
     * {@see MemoryReplayCoordinator} to scope a crash-resume replay's
     * {@see ReplaySwarmMemory} to this run only, rather than rebinding the
     * container's `SwarmMemory` process-wide.
     */
    public static function withMemoryOverride(SwarmMemory $memory): void
    {
        $top = self::current();

        if ($top !== null) {
            $top->memoryOverride = $memory;
        }
    }

    /**
     * Clear the frozen-memory override on the top frame. Idempotent: a no-op
     * when there is no frame or no override is set.
     */
    public static function clearMemoryOverride(): void
    {
        $top = self::current();

        if ($top !== null) {
            $top->memoryOverride = null;
        }
    }

    /**
     * Resolve the frozen-memory override in effect for the current invocation,
     * if any. Walks the frame stack top-down so an inner frame pushed for the
     * agent invocation (e.g. by a runner's own {@see enter()}) inherits the
     * override carried by the enclosing replay frame. Returns null outside a
     * replay, so reads fall back to the live, container-bound `SwarmMemory`.
     */
    public static function currentMemory(): ?SwarmMemory
    {
        for ($i = array_key_last(self::$stack); $i !== null && $i >= 0; $i--) {
            if (self::$stack[$i]->memoryOverride !== null) {
                return self::$stack[$i]->memoryOverride;
            }
        }

        return null;
    }

    /**
     * Discard every frame. Intended for an Octane/worker-reset hook so a stale
     * frame left by an abnormally-terminated run never outlives the request that
     * produced it; also useful for deterministic test isolation.
     */
    public static function flush(): void
    {
        self::$stack = [];
    }
}
