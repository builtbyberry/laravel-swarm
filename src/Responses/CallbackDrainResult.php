<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

/**
 * Result of a single CallbackDeliveryOutbox::drain() invocation.
 *
 * The callback lane leases eligible rows with an opaque claim token and hands each
 * to a DeliverSwarmCallback job. Attempts increment only when that job atomically
 * acquires pending → delivering. A stale delivering row that exhausted the budget is
 * dead-lettered instead of receiving a replacement lease; this also covers pending
 * rows already at the cap after an operator lowers max_attempts.
 *
 * - dispatched: rows handed to a delivery job this drain (reserved + job queued).
 * - dead_lettered: eligible pending or stale-delivering rows already at the attempt cap.
 * - failed:       rows that could not be handed to a job due to a transient error
 *                 (queue driver unavailable, etc.). NOT dispatched — the lease is
 *                 released with retry backoff.
 * - claimed:      total rows selected under lock this drain, including capped work
 *                 dead-lettered instead of receiving a new lease.
 * - reclaimed:    subset of selected rows whose reserved_at was already set before this
 *                 drain — indicates a prior relay claim or delivery did not complete.
 *
 * total() returns dispatched + dead_lettered (rows that left the pending-dispatch set
 * this drain); failed rows remain claimable and are not counted.
 */
final readonly class CallbackDrainResult
{
    public function __construct(
        public int $dispatched,
        public int $deadLettered = 0,
        public int $failed = 0,
        public int $claimed = 0,
        public int $reclaimed = 0,
    ) {}

    public function total(): int
    {
        return $this->dispatched + $this->deadLettered;
    }
}
