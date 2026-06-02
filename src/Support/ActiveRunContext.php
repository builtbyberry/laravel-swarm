<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Concerns\RemembersRunContext;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\Conversational;

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
 * worker recycles. If you want it cleared eagerly under Octane, reset it from a
 * worker/operationTerminated hook with {@see flush()}.
 *
 * @internal
 */
final class ActiveRunContext
{
    /** @var list<ActiveRunRecord> */
    private static array $stack = [];

    public static function enter(string $runId, string $swarmClass, RunContext $context): void
    {
        self::$stack[] = new ActiveRunRecord($runId, $swarmClass, $context);
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
     * Discard every frame. Intended for an Octane/worker-reset hook so a stale
     * frame left by an abnormally-terminated run never outlives the request that
     * produced it; also useful for deterministic test isolation.
     */
    public static function flush(): void
    {
        self::$stack = [];
    }
}
