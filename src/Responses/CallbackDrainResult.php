<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

/**
 * Result of a single CallbackDeliveryOutbox::drain() invocation.
 *
 * The callback lane claims pending rows, increments each row's attempt count at
 * claim time (so a delivery that dies mid-flight still advances toward the cap and
 * cannot be re-invoked forever), and hands rows under the cap to a
 * DeliverSwarmCallback job. A row that has exhausted swarm.callbacks.max_attempts
 * across claims is dead-lettered at claim time instead of dispatched.
 *
 * - dispatched: rows handed to a delivery job this drain (reserved + job queued).
 * - dead_lettered: rows that reached the attempt cap and were moved to 'dead_letter'
 *                  at claim time instead of being dispatched again.
 * - failed:       rows that could not be handed to a job due to a transient error
 *                 (queue driver unavailable, etc.). NOT dispatched — the reservation
 *                 is LEFT IN PLACE so the row is re-claimable after the reservation
 *                 timeout.
 * - claimed:      total rows atomically reserved (and attempt-incremented) this drain.
 * - reclaimed:    subset of claimed rows whose reserved_at was already set before this
 *                 drain — indicates a prior relay run or delivery claimed but did not
 *                 complete.
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
