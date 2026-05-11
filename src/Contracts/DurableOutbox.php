<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Enums\OutboxDispatchType;

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
     * @param  array<OutboxDispatchType>  $types  Restrict to these types; empty means all.
     * @return int  Number of entries successfully dispatched.
     */
    public function drain(array $types = [], int $limit = 100): int;
}
