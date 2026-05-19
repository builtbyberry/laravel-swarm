<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

/**
 * Result of a single AuditOutbox::drain() invocation.
 *
 * - replayed:    records successfully re-emitted through the bound sink and deleted.
 * - dead_lettered: records that hit swarm.audit.outbox.max_attempts during this drain
 *                  and were moved to the dead-letter status.
 * - failed:      records that failed re-emission this drain with attempts still under
 *                the cap. Reservation is released so they become re-claimable after the
 *                reservation timeout.
 * - claimed:     total rows atomically reserved in phase 1 of this drain call.
 * - reclaimed:   subset of claimed rows whose reserved_at was already set before this
 *                drain — indicates a prior relay run claimed but did not complete.
 *
 * total() returns replayed + dead_lettered (records that left the pending state).
 */
final class AuditDrainResult
{
    public function __construct(
        public readonly int $replayed,
        public readonly int $deadLettered = 0,
        public readonly int $failed = 0,
        public readonly int $claimed = 0,
        public readonly int $reclaimed = 0,
    ) {}

    public function total(): int
    {
        return $this->replayed + $this->deadLettered;
    }
}
