<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

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
 * @internal This contract is schema-coupled to {@see DatabaseCausalLogStore} (its
 * sole implementation) and evolves with the substrate; it is not a consumer
 * extension point. Consumers wanting a custom persistence backend implement the
 * public {@see StreamEventStore} instead.
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
}
