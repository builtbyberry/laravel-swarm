<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Enums\OutboxDispatchType;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;

interface DurableOutbox
{
    /**
     * Enqueue a step dispatch. Must be called inside the same DB transaction
     * as the state change it accompanies so the write is atomic.
     */
    public function enqueueStep(string $runId, int $stepIndex, ?string $connection, ?string $queue): void;

    /**
     * Enqueue a branch dispatch. Must be called inside the same DB transaction
     * as the state change it accompanies so the write is atomic.
     */
    public function enqueueBranch(string $runId, string $branchId, ?string $connection, ?string $queue): void;

    /**
     * Enqueue a queued-hierarchical-parallel resume. Must be called inside the
     * same DB transaction as the state change it accompanies so the write is atomic.
     */
    public function enqueueQueuedResume(string $runId, ?string $connection, ?string $queue): void;

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
     *                 before being overwritten — indicates the relay previously claimed but
     *                 did not complete these entries (e.g. the relay process was killed or
     *                 timed out mid-run). A non-zero value signals a falling-behind relay.
     *
     * @param  array<OutboxDispatchType>  $types  Restrict to these types; empty means all.
     * @param  int  $limit  Maximum rows to claim per call. Values < 1 return an empty DrainResult immediately.
     */
    public function drain(array $types = [], int $limit = 100): DrainResult;
}
