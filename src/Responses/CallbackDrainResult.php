<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

/**
 * Result of a single CallbackDeliveryOutbox::drain() invocation.
 *
 * The callback lane claims pending rows and hands each to a DeliverSwarmCallback
 * job; the job owns the actual invocation and the row's terminal fate.
 *
 * - dispatched: rows successfully handed to a delivery job (reserved + job queued).
 * - skipped:    rows permanently invalid (unknown slot, unsealable/malformed
 *               closure) and moved out of the pending set without dispatch. Each
 *               is reported via report() so it surfaces in the error tracker.
 * - failed:     rows that could not be handed to a job due to a transient error
 *               (queue driver unavailable, etc.). NOT dispatched — the reservation
 *               is released so they are re-claimable after the reservation timeout.
 * - claimed:    total rows atomically reserved in phase 1 of this drain call.
 * - reclaimed:  subset of claimed rows whose reserved_at was already set before this
 *               drain — indicates a prior relay run claimed but did not complete.
 *
 * total() returns dispatched + skipped (rows that left the pending set this drain).
 */
final readonly class CallbackDrainResult
{
    public function __construct(
        public int $dispatched,
        public int $skipped = 0,
        public int $failed = 0,
        public int $claimed = 0,
        public int $reclaimed = 0,
    ) {}

    public function total(): int
    {
        return $this->dispatched + $this->skipped;
    }
}
