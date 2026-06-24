<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

/**
 * Result of a single DurableOutbox::drain() invocation.
 *
 * - dispatched: entries successfully dispatched to a queue driver and deleted from the outbox.
 * - skipped:    entries permanently invalid (unknown dispatch_type, unknown queue_connection,
 *               or malformed payload) and deleted without dispatch. Each is reported via
 *               report() so it appears in the application error tracker.
 * - failed:     entries that could not be dispatched due to a transient error (queue driver
 *               unavailable, network blip, etc.). These are NOT deleted — they retain their
 *               reserved_at timestamp and become re-claimable after the configured reservation
 *               timeout. Each is reported via report() so the outage appears in the error tracker.
 * - claimed:    total rows atomically reserved in phase 1 of this drain call.
 * - reclaimed:  subset of claimed rows whose reserved_at was already set (non-null) before being
 *               overwritten — indicates the relay previously claimed but did not complete these
 *               entries (e.g. the relay process was killed or timed out mid-run).
 *
 * total() returns dispatched + skipped (entries removed from the outbox). It does not include
 * failed, because failed entries remain in the outbox and are not "done".
 */
final readonly class DrainResult
{
    public function __construct(
        public int $dispatched,
        public int $skipped,
        public int $failed = 0,
        public int $claimed = 0,
        public int $reclaimed = 0,
    ) {}

    /**
     * Total entries removed from the outbox in this drain (dispatched + skipped).
     *
     * Does not include transient failures — those entries remain in the outbox and
     * will be re-claimed after the reservation timeout. Use $failed to detect them.
     */
    public function total(): int
    {
        return $this->dispatched + $this->skipped;
    }
}
