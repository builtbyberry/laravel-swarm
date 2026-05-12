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
     * Claim and dispatch pending outbox entries, then delete them.
     *
     * Returns a DrainResult describing how many entries were dispatched to a queue
     * driver and how many were permanently invalid and deleted without dispatch.
     * Transient dispatch failures are counted in neither — those entries retain their
     * reserved_at and are re-claimable after the configured reservation timeout.
     *
     * @param  array<OutboxDispatchType>  $types  Restrict to these types; empty means all.
     * @param  int  $limit  Maximum rows to claim per call. Values < 1 return an empty DrainResult immediately.
     */
    public function drain(array $types = [], int $limit = 100): DrainResult;
}
