<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Public, read-only health seam over the callback-delivery outbox — the persisted
 * buffer of terminal workflow then/catch callbacks awaiting or failing delivery.
 *
 * This is the non-mutating counterpart to {@see CallbackDeliveryOutbox::drain()},
 * which RESERVES rows for delivery. A display consumer (a swarm:health summary, a
 * dashboard card) that called drain() would hand rows to delivery jobs out from
 * under the real `swarm:relay --type=callback` drainer. Every read here is a pure
 * SELECT: it never writes `reserved_at`, never deletes, and never contends with a
 * concurrent drainer.
 *
 * ## Display-decrypt contract
 *
 * The sealed `callback` closure is NEVER returned by this seam — it is executable
 * code and its captured bindings may be sensitive. Reads expose row metadata and
 * the display-decrypted short `last_error` only, through the evidence path that
 * honors `swarm.persistence.decrypt_failure_policy`: a value that cannot be
 * decrypted becomes null with an explicit availability flag rather than throwing
 * or leaking `sw0:` ciphertext.
 *
 * Consumers MUST bind this contract, never the `@internal` concrete outbox. The
 * default binding resolves the database-backed outbox when the persistence driver
 * supports it, and a no-op (reporting an empty, unavailable outbox) otherwise —
 * mirroring how {@see ReadableAuditOutbox} is bound.
 */
interface ReadableCallbackDeliveryOutbox
{
    /**
     * Whether the outbox is backed by a working store. False under a non-database
     * persistence driver and when the `swarm_callback_deliveries` table is missing;
     * a display surface should render an "unavailable" empty state.
     */
    public function isAvailable(): bool;

    /**
     * Rows awaiting delivery ('registered' or 'pending'), newest first. Metadata +
     * display-decrypted `last_error` only — never the sealed closure.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(int $limit = 100): array;

    /**
     * Rows that exhausted `swarm.callbacks.max_attempts` and moved to the
     * dead-letter status, newest first. Metadata + display-decrypted `last_error`
     * only — never the sealed closure.
     *
     * @return array<int, array<string, mixed>>
     */
    public function deadLettered(int $limit = 100): array;

    /**
     * A non-mutating health summary: row counts by delivery state, fresh and stale
     * leases, aged eligible work, and its oldest timestamp. No decryption — counts
     * and timestamps only.
     *
     * @return array{available: bool, registered: int, pending: int, delivering: int, dead_letter: int, fresh_reservations: int, stale_pending_reservations: int, stale_deliveries: int, aged_eligible: int, oldest_eligible_at: ?string}
     */
    public function healthSummary(): array;
}
