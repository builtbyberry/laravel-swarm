<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Runners\DispatchValidator;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalSealBarrier;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Per-node durable streaming sink over the append-only causal log (#298).
 *
 * Bridges a durable step advancer to the causal log: when the run's pinned
 * `#[DurableStreaming]` opt-in is on AND a database causal log is bound, it builds a
 * per-attempt sink that stamps every streamed event with its node id and attempt
 * epoch and appends it, and it retracts a crashed prior attempt before the fresh one
 * re-emits. When the opt-in is off (the default) every method is inert, so the
 * advancer falls through to the unchanged blocking `prompt()` path and existing
 * durable runs are untouched.
 *
 * Two gates, deliberately split (#310 KS1): {@see enabled()} (pin-only) governs the
 * causal-log INTEGRITY operations — the void-on-resume and the seal-on-commit — so
 * they always run for a pinned run and the fold stays consistent. {@see streamingActive()}
 * additionally honours the live `swarm.durable.streaming_enabled` operator kill-switch
 * and governs only EMISSION (whether a node streams via the sink or falls back to
 * `prompt()`). So an operator can stop the per-event write load mid-incident without
 * ever dropping a retraction or seal — the kill-switch can force streaming off, never on.
 *
 * State discipline (#298 F4): nothing per-attempt is held on this object. The sink
 * is a fresh closure per `advance()` call, capturing the node id and epoch in its
 * own scope, so two concurrent durable runs in one Octane worker never share a
 * step's buffer or stamp each other's events.
 *
 * The attempt epoch is the durable run's recovery count: it is bumped before any
 * recovery/retry re-dispatch (see {@see DatabaseDurableRunStore::markRecoveryDispatched()}),
 * so a re-executed node always streams under a strictly higher epoch than its
 * crashed attempt — the authoritative rollback discriminator the nullable vendor
 * `invocation_id` cannot provide.
 *
 * @internal
 */
class DurableNodeStreamRecorder
{
    public function __construct(
        protected CausalLogStore $causalLog,
        protected ConfigRepository $config,
    ) {}

    /**
     * Whether the causal-log INTEGRITY operations (void-on-resume, seal-on-commit)
     * run for this step: the run's pinned `#[DurableStreaming]` opt-in is on AND the
     * bound causal log is the database store. Deliberately does NOT consult the
     * operator kill-switch — integrity must hold even while emission is paused, so a
     * crashed attempt is always retracted and every committed node always sealed.
     *
     * The pinned value is threaded in by the caller from the durable run row (never
     * read from live config), so a relayed/recovered job sees the run's original
     * decision. {@see DispatchValidator} enforces
     * the database-causal-log requirement at dispatch; it is re-checked here so an
     * advancer is correct in isolation.
     */
    public function enabled(bool $pinned): bool
    {
        return $pinned && $this->causalLog instanceof DatabaseCausalLogStore;
    }

    /**
     * Whether this node should EMIT a stream (use the sink) rather than fall back to
     * `prompt()`: integrity is enabled AND the live operator kill-switch
     * `swarm.durable.streaming_enabled` (default true) is on. The kill-switch lets an
     * operator shed the per-event causal-log write load fleet-wide without a redeploy;
     * because it gates only emission, flipping it mid-run never orphans events.
     */
    public function streamingActive(bool $pinned): bool
    {
        return $this->enabled($pinned)
            && (bool) $this->config->get('swarm.durable.streaming_enabled', true);
    }

    /**
     * Retract the node's crashed prior attempt before it re-executes (#298 F2/F3).
     *
     * Finds the highest attempt epoch below `$epoch` for the node — the prior
     * (crashed) attempt, since the fresh attempt has not emitted yet — and appends
     * one idempotent `node_reexecuted` void-edge against its first event. A no-op on
     * a first attempt (no earlier epoch), when the prior attempt streamed nothing,
     * or when streaming is off. Must run under the step lease the caller already
     * holds, before any fresh event is written (#298 F5).
     */
    public function voidPriorAttempt(string $runId, string $nodeId, int $epoch, bool $pinned): void
    {
        if (! $this->enabled($pinned)) {
            return;
        }

        $priorEpoch = $this->causalLog->latestAttemptEpochBelow($runId, $nodeId, $epoch);

        if ($priorEpoch === null) {
            return;
        }

        $this->causalLog->voidNodeAttempt(
            $runId,
            $nodeId,
            $priorEpoch,
            'durable node re-executed on resume',
            $this->ttlSeconds(),
        );
    }

    /**
     * Emit the per-node seal barrier that marks this committed step's events as
     * graduatable (#298 F1). MUST be called inside the lease-fenced step-commit
     * transaction (the recorder's checkpoint txn) so the barrier commits atomically
     * with the cursor advance: an uncommitted (crashed) node's events always stay
     * above the last committed barrier — unsealed, hence retractable on resume — and
     * compaction (#287/#288/#289) only graduates below a barrier, so it can never
     * seal an in-flight node. No-op when streaming is off.
     *
     * Deliberately not best-effort: a barrier-write failure propagates so the whole
     * checkpoint rolls back and the step re-executes on recovery (safe — its events
     * are unsealed and voidable), rather than advancing the cursor without the
     * barrier that fences the next attempt's retraction. The dispatch gate (F7)
     * makes a mid-run causal-log failure a genuine infrastructure fault.
     */
    public function sealNodeBoundary(string $runId, bool $pinned): void
    {
        if (! $this->enabled($pinned)) {
            return;
        }

        $this->causalLog->record($runId, new SwarmCausalSealBarrier(
            id: SwarmStreamEvent::newId(),
            runId: $runId,
            timestamp: SwarmStreamEvent::timestamp(),
        ), $this->ttlSeconds());
    }

    /**
     * Build a per-attempt sink: it stamps each event with `$nodeId` and `$epoch`
     * and appends it to the causal log. Returned as a fresh closure per call so the
     * per-attempt identity lives only in the closure's scope (#298 F4), never on a
     * shared field.
     *
     * @return callable(SwarmStreamEvent): void
     */
    public function sinkFor(string $runId, string $nodeId, int $epoch): callable
    {
        $ttlSeconds = $this->ttlSeconds();

        return function (SwarmStreamEvent $event) use ($runId, $nodeId, $epoch, $ttlSeconds): void {
            $event->withNodeId($nodeId)->withAttemptEpoch($epoch);
            $this->causalLog->record($runId, $event, $ttlSeconds);
        };
    }

    /**
     * Match the durable run's data TTL so streamed events expire with the rest of
     * the run, rather than outliving it.
     */
    protected function ttlSeconds(): int
    {
        return (int) $this->config->get('swarm.context.ttl', 3600);
    }
}
