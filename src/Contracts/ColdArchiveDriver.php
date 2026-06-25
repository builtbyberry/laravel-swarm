<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;

/**
 * Read-side contract for a cold archive tier in the hot/cold substrate (#286).
 *
 * The compactor (#287) writes to cold and swaps the base pointer atomically;
 * this contract exposes only the read surface. The write-side protocol
 * (graduate events, advance pointer) is internal to the compactor and is not
 * expressed here — the contract documents the atomicity requirement so any
 * future compactor implementation can be verified against it.
 *
 * Atomic swap requirement: the compactor MUST advance the base pointer with a
 * single-pointer CAS swap (old-or-new, never half). A reader that observes a
 * non-zero base pointer is guaranteed that all events with id < that value are
 * durably available via readEvents(), and that the snapshot (if any) was written
 * before the pointer was advanced.
 *
 * @internal
 */
interface ColdArchiveDriver
{
    /**
     * Returns the hot/cold boundary sequence number.
     * Events with DB id < base live in cold; id >= base live in hot.
     * Returns 0 (all-hot) when no data has been graduated yet.
     */
    public function basePointer(string $runId): int;

    /**
     * Yields raw (unsealed) SwarmStreamEvent objects for this run
     * with DB id < $belowSequence, in ascending causal order.
     * Returns an empty iterable when no cold events exist (not an error).
     */
    public function readEvents(string $runId, int $belowSequence): iterable;

    /**
     * Returns the sealed fold-snapshot string for operational resume,
     * or null if nothing has been graduated yet.
     * Callers MUST decrypt via openStrict and wrap DecryptException → SwarmException.
     * Failure (driver/network error) must throw, not return null.
     *
     * The driver must retain both the snapshot (for resume) and raw events (for audit).
     * Cold storage must be addressable by logical coordinate (run_id + sequence range).
     *
     * The compactor (#287) will graduate events by:
     *   1. Writing snapshot + raw events to cold (addressable by coordinate).
     *   2. Advancing the base pointer atomically with a single-pointer CAS swap.
     * The driver contract requires this swap be atomic (old-or-new, never half).
     */
    public function readSnapshot(string $runId): ?string;
}
