<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Concerns\RemembersRunContext;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\Agent;
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
 * (queue, Octane) anything that bypasses the finally can leave a stale frame:
 * an abnormal termination (a hard timeout or fatal), or — reachably, in normal
 * code — a streamed run abandoned while its iterator is still referenced, since
 * the generator's finally does not run until it is released. For the readers
 * that only ask "which run am I in", that is harmless: {@see current()} returns
 * the top, and the next run's {@see enter()} pushes a fresh frame above it.
 * Normal exits do not shadow one another: {@see exit()} removes the frame of the
 * run that is leaving, so a run finishing while another straddles it leaves the
 * straddling run's frame in place.
 * No reader treats a frame's mere presence as "a swarm run is executing": the
 * native tool-failure propagation listener in {@see SwarmServiceProvider} asks
 * {@see isInvoking()} instead, precisely because a frame is legitimately live
 * while a consumer iterates a swarm stream — that is when its tool calls run —
 * and may be left behind by an abandoned one. Proven by the live-frame cases in
 * NativeToolFailurePropagationTest.
 * Beyond that, the residue is a single retained {@see RunContext} reference
 * until the worker recycles. Under Laravel Octane it is cleared eagerly: when
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

    /**
     * Leave a run's frame, removing that run's OWN frame rather than the top.
     *
     * Two swarm runs can be live at once without nesting: an application
     * consuming two streams in an interleaved way enters one run's frame, then
     * the other's, and the first can finish while the second is still suspended
     * mid-step -- its frame live, its invocation already recorded by
     * {@see invoking()}, its tool not yet run. A plain `array_pop` discarded the
     * suspended run's frame there, after which {@see isInvoking()} could not
     * find it and the native tool-failure listener silently recovered a genuine
     * swarm failure. So enter/exit are LIFO within one run's control flow but
     * not globally, and the frame to remove is named rather than assumed.
     *
     * The topmost frame carrying `$runId` is removed, which keeps re-entry with
     * the same id (memory replay does this) correctly LIFO. A `$runId` with no
     * frame leaves the stack untouched. Passing nothing pops the top, retained
     * for callers that genuinely own it; every production call site passes its
     * own run id. Guarded by tests/Unit/ActiveRunContextInvokingTest.php.
     */
    public static function exit(?string $runId = null): void
    {
        if ($runId === null) {
            array_pop(self::$stack);

            return;
        }

        for ($i = array_key_last(self::$stack); $i !== null && $i >= 0; $i--) {
            if (self::$stack[$i]->runId === $runId) {
                $remaining = self::$stack;
                array_splice($remaining, $i, 1);
                self::$stack = $remaining;

                return;
            }
        }
    }

    public static function current(): ?ActiveRunRecord
    {
        return self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
    }

    /**
     * Record the Laravel AI agent Swarm is invoking, on the frame of the run
     * making the call. Called by {@see NativeAgentInvoker} for every prompted
     * and streamed invocation, with the configured instance actually invoked.
     *
     * The run is NAMED rather than assumed to be on top, for the same reason
     * {@see exit()} names it: two swarm runs can be live at once without
     * nesting, and a runner that yields before invoking (every streamed runner
     * does) can have another run's frame pushed above its own in the meantime.
     * Writing to `current()` then recorded this run's invocation on the other
     * run's frame and destroyed the record that was there. Under plain
     * round-robin consumption of two streams, the run whose record was
     * overwritten had {@see isInvoking()} answer false for it, so its own
     * failing tool was silently recovered and the run reported `completed` --
     * measured as one of the two runs on that schedule, the other still
     * failing. Which run loses out depends on the interleaving; what matters is
     * that a run's own failure can go missing at all. Guarded end to end by the
     * interleaving case in
     * tests/Feature/Adoption/NativeToolFailurePropagationTest.php.
     *
     * The topmost frame carrying `$runId` is used, matching {@see exit()}. A
     * `$runId` with no frame is a no-op, which is how the invoker behaves
     * outside any swarm run.
     */
    public static function invoking(string $runId, Agent $agent): void
    {
        for ($i = array_key_last(self::$stack); $i !== null && $i >= 0; $i--) {
            if (self::$stack[$i]->runId === $runId) {
                self::$stack[$i]->invokingAgent = $agent;

                return;
            }
        }
    }

    /**
     * Whether the given agent is one Swarm is invoking in any live frame.
     *
     * This is what distinguishes a tool failure inside a swarm run from one in
     * an agent the application prompted itself. A frame alone is not enough: it
     * stays live for the whole time a consumer iterates a swarm stream, since
     * that is when the stream's tool calls run, so an application prompting its
     * own agent from inside that loop would otherwise look in-run.
     *
     * Walks the whole stack, as {@see currentMemory()} does, rather than reading
     * the top: an application consuming two swarm streams in an interleaved way
     * suspends one run's frame under the other's, and the lower run's tool calls
     * still execute when its generator is resumed. Reading only the top would
     * silently recover a genuine swarm failure, which is a worse error than the
     * one this check exists to prevent. The walk is one of three parts that
     * topology needs: {@see invoking()} must also write to the invoking run's
     * own frame and {@see exit()} remove that run's own frame, or the records
     * the walk looks for are overwritten or discarded. All three are guarded
     * together by the round-robin case in
     * tests/Feature/Adoption/NativeToolFailurePropagationTest.php.
     *
     * Identity, not class: two instances of the same agent class are different
     * invocations. That is also the residual limit: an application prompting the
     * very same instance Swarm is invoking is indistinguishable from the swarm's
     * own call, including after a streamed run was abandoned, since
     * {@see invoking()} is deliberately not cleared for a stream whose tool
     * calls arrive after the call that started it returned.
     */
    public static function isInvoking(Agent $agent): bool
    {
        for ($i = array_key_last(self::$stack); $i !== null && $i >= 0; $i--) {
            if (self::$stack[$i]->invokingAgent === $agent) {
                return true;
            }
        }

        return false;
    }

    /**
     * Note on the top frame that a tool call stored nothing. Keyed by the
     * Laravel AI invocation that made the call, so a nested agent's decline
     * can never be attributed to its parent. A no-op without a frame or
     * without both ids.
     */
    public static function declineToolCall(?string $invocationId, ?string $toolCallId, string $message): void
    {
        $top = self::current();

        if ($top === null || $invocationId === null || $invocationId === '' || $toolCallId === null || $toolCallId === '') {
            return;
        }

        $top->declinedToolCalls[] = [
            'invocation' => $invocationId,
            'id' => $toolCallId,
            'message' => $message,
        ];
    }

    /**
     * Take one matching declined call off the top frame. The result text must
     * equal the decline message, so a later call reusing the same id is not
     * mistaken for the declined one.
     */
    public static function consumeDeclinedToolCall(?string $invocationId, string $toolCallId, string $resultText): bool
    {
        $top = self::current();

        if ($top === null || $invocationId === null) {
            return false;
        }

        foreach ($top->declinedToolCalls as $index => $declined) {
            if ($declined['invocation'] !== $invocationId
                || $declined['id'] !== $toolCallId
                || $declined['message'] !== $resultText) {
                continue;
            }

            array_splice($top->declinedToolCalls, $index, 1);

            return true;
        }

        return false;
    }

    /**
     * Forget the top frame's declined calls. Called around each agent
     * invocation so one step's declines never outlive it on a long-lived frame.
     */
    public static function resetDeclinedToolCalls(): void
    {
        $top = self::current();

        if ($top !== null) {
            $top->declinedToolCalls = [];
        }
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
