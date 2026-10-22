<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Enums\DurableDispatchType;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;

interface DurableOutbox
{
    /**
     * Enqueue a step dispatch and return the new entry's id. Must be called inside
     * the same DB transaction as the state change it accompanies so the write is atomic.
     *
     * Pass $directDispatch = true only when the caller will push the advance job
     * itself once the transaction commits: the entry is then written already
     * reserved, so the relay leaves it alone until the reservation goes stale and
     * recovers it only if the pushed job never settles it (see settle()).
     */
    public function enqueueStep(string $runId, int $stepIndex, ?string $connection, ?string $queue, bool $directDispatch = false): int;

    /**
     * Enqueue a branch dispatch and return the new entry's id. Must be called inside
     * the same DB transaction as the state change it accompanies so the write is atomic.
     *
     * $directDispatch has the same meaning as on enqueueStep().
     */
    public function enqueueBranch(string $runId, string $branchId, ?string $connection, ?string $queue, bool $directDispatch = false): int;

    /**
     * Enqueue a queued-hierarchical-parallel resume. Must be called inside the
     * same DB transaction as the state change it accompanies so the write is atomic.
     */
    public function enqueueQueuedResume(string $runId, ?string $connection, ?string $queue): void;

    /**
     * Delete one entry by id once the job it describes has been delivered, so the
     * relay never dispatches it again. Deleting an entry that no longer exists is
     * a no-op. Implementations must not throw: a missed settle only costs one
     * duplicate, no-op dispatch once the entry's reservation goes stale.
     */
    public function settle(int $entryId): void;

    /**
     * Claim and dispatch pending outbox entries, then delete successfully dispatched rows.
     *
     * Returns a DrainResult with five counters:
     *   - dispatched: entries sent to a queue driver and deleted from the outbox.
     *   - skipped:    entries permanently invalid (unknown dispatch_type, unknown
     *                 queue_connection, or malformed payload) and deleted without dispatch.
     *                 Each is reported via report() so it surfaces in the error tracker.
     *   - failed:     entries that could not be dispatched due to a transient error
     *                 (queue driver unavailable, network blip, etc.). These are NOT deleted;
     *                 they retain reserved_at and are re-claimable after the reservation
     *                 timeout. Each is reported via report() so the outage is visible.
     *   - claimed:    total rows atomically reserved in phase 1 of this drain call.
     *   - reclaimed:  subset of claimed rows whose reserved_at was already set (non-null)
     *                 before being overwritten — either a relay previously claimed but
     *                 did not complete these entries (e.g. the relay process was killed or
     *                 timed out mid-run), or they were written for direct dispatch and the
     *                 pushed job did not settle them before the reservation went stale.
     *                 A non-zero value signals a falling-behind relay or lost/slow direct pushes.
     *
     * @param  array<DurableDispatchType>  $types  Restrict to these types; empty means all.
     * @param  int  $limit  Maximum rows to claim per call. Values < 1 return an empty DrainResult immediately.
     */
    public function drain(array $types = [], int $limit = 100): DrainResult;
}
