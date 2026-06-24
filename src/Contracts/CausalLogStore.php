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
     */
    public function appendVoidEdge(
        string $runId,
        CausalVoidEdgeType $type,
        string $targetEventId,
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
