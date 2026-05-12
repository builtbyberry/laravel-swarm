<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Result of a single DurableOutbox::drain() invocation.
 *
 * - dispatched: entries that were successfully dispatched to a queue driver and deleted.
 * - skipped:    entries that were permanently invalid (unknown dispatch_type or malformed
 *               payload) and deleted without being dispatched. These are reported via
 *               report() so they appear in the application error tracker.
 *
 * Entries that failed dispatch due to a transient error (queue driver unavailable, network
 * blip, etc.) are counted in neither field — they retain their reserved_at timestamp and
 * become re-claimable after the configured reservation timeout.
 */
final class DrainResult
{
    public function __construct(
        public readonly int $dispatched,
        public readonly int $skipped,
    ) {}

    /**
     * Total entries removed from the outbox in this drain (dispatched + skipped).
     * Useful as a loop-continuation signal when using --drain-until-empty.
     */
    public function total(): int
    {
        return $this->dispatched + $this->skipped;
    }
}
