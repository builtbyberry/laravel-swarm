<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Compaction\SwarmCompactor;
use BuiltByBerry\LaravelSwarm\Exceptions\SealedCausalWindowException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnknownCausalTargetException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;

/**
 * The append-only causal log over the stream-event store (#282).
 *
 * Extends {@see StreamEventStore} with the void-edge surface: course-corrections
 * are appended as typed edges that point at the event they void, never as
 * deletions. The log stays the single source of truth; every later shaping
 * (supersession, hot/cold tiering) is a read-time fold.
 *
 * This contract is database-only — void-edges require indexed UUID lookup and
 * row-level locking the cache driver cannot provide. Implementations bound under
 * a non-database persistence driver must fail loud (see
 * {@see DatabaseCausalLogStore}).
 *
 * Public since v0.17.0 (#349), stable for two minors since its v0.15.0
 * introduction. This is a **read/query-seam extension point**: a custom
 * implementation is consumed by the runtime read paths (durable per-node
 * streaming's void-on-resume, hierarchical stream causal-log resolution). It is
 * NOT consumed by compaction — {@see SwarmCompactor}
 * depends on the concrete {@see DatabaseCausalLogStore} directly, so a custom
 * implementation's runs are never compacted. `#[DurableStreaming]` per-node
 * streaming additionally requires the concrete database implementation and
 * fails loud otherwise (see `DispatchValidator::ensureDurableStreamingInfrastructure`).
 * See `docs/streaming-substrate-driver-guide.md` for the full driver-author story.
 */
interface CausalLogStore extends StreamEventStore
{
    /**
     * Append a typed void-edge against an earlier event in the run.
     *
     * Fails loud rather than corrupting history: throws
     * {@see SealedCausalWindowException} if
     * the target has been sealed, and
     * {@see UnknownCausalTargetException} if
     * no event with `$targetEventId` exists in the run.
     *
     * `$digestNodeId` is the rollup node that reads in the target's place for a
     * {@see CausalVoidEdgeType::RolledUp} edge (#289); null for every other type.
     */
    public function appendVoidEdge(
        string $runId,
        CausalVoidEdgeType $type,
        string $targetEventId,
        string $reason,
        int $ttlSeconds = 0,
        ?string $digestNodeId = null,
    ): void;

    /**
     * Atomically seal a rolled-up generation (#289): append a `rolled_up`
     * void-edge against each `$targetEventIds` entry and a single seal barrier,
     * all in one transaction so a crash can never leave a partial rollup the
     * compactor would graduate. Idempotent — a target already carrying a
     * `rolled_up` edge (e.g. a re-dispatched pass) is skipped, not double-voided.
     * The barrier is recorded last so the compactor, which keys on the barrier,
     * never sees a window whose edges were only partially written.
     *
     * Throws (for the caller to treat as best-effort) on a sealed target or an
     * unready store; the operational prune that precedes it is independent.
     *
     * @param  list<string>  $targetEventIds  the digested nodes' current step-end event uuids
     */
    public function sealRollup(
        string $runId,
        array $targetEventIds,
        string $digestNodeId,
        string $reason,
        int $ttlSeconds = 0,
    ): void;

    /**
     * Whether the event identified by `$eventUuid` within `$runId` has been
     * sealed (passed out of the unsealed, retractable window). Always false until
     * the #287 compactor populates `sealed_at`.
     */
    public function isSealed(string $runId, string $eventUuid): bool;

    /**
     * Retract a durable node's prior attempt before it re-executes on resume (#298).
     *
     * Appends a single `node_reexecuted` void-edge against the prior attempt's
     * first event for `(runId, nodeId, epoch)`, located by a metadata-only query
     * (the queryable `node_id` / `attempt_epoch` columns — never decrypting
     * payload). The fold suppresses the whole `(node_id, epoch)` membership from
     * that one edge, so the retracted attempt's events are hidden while the fresh
     * attempt (a higher epoch, same node_id) survives.
     *
     * Idempotent — a redelivered or repeated resume that finds an existing
     * `node_reexecuted` edge for that attempt is a no-op, never a double-void. A
     * no-op too when the prior attempt streamed nothing (crash before first event).
     *
     * Relies on the seal-follows-commit invariant: an uncommitted node's events are
     * never sealed, so the target is always in the retractable window. If that
     * invariant is ever violated the underlying {@see appendVoidEdge()} throws
     * {@see SealedCausalWindowException} loud rather than silently leaving two
     * attempts in the fold.
     */
    public function voidNodeAttempt(
        string $runId,
        string $nodeId,
        int $epoch,
        string $reason,
        int $ttlSeconds = 0,
    ): void;

    /**
     * The highest `attempt_epoch` recorded for `(runId, nodeId)` strictly below
     * `$epoch`, or null when the node has no earlier attempt in the log (#298).
     *
     * Metadata-only — reads the indexed `node_id` / `attempt_epoch` columns, never
     * decrypting payload. The durable advancer calls this on resume to locate the
     * crashed prior attempt it must retract via {@see voidNodeAttempt()} before the
     * fresh attempt re-emits under a higher epoch. Because the void runs before the
     * fresh attempt writes anything, the maximum below the fresh epoch is exactly
     * the prior attempt; older already-voided attempts keep their own edges.
     */
    public function latestAttemptEpochBelow(string $runId, string $nodeId, int $epoch): ?int;
}
