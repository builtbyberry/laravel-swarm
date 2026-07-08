<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Public, read-only health seam over the audit outbox — the persisted retry
 * buffer that holds audit evidence which failed to emit through the bound
 * {@see SwarmAuditSink}.
 *
 * This is the non-mutating counterpart to {@see AuditOutbox::drain()}, which
 * RESERVES and CONSUMES rows for re-delivery. A display consumer (a Filament
 * outbox-health card, an MCP audit reader) that called drain() would destroy
 * audit evidence out from under the real `swarm:relay --type=audit` drainer.
 * Every read here is a pure SELECT: it never writes `reserved_at`, never
 * deletes, and never contends with a concurrent drainer.
 *
 * ## Display-decrypt contract
 *
 * Outbox `payload` and `last_error` are sealed at rest. They are read here
 * through the evidence path that honors `swarm.persistence.decrypt_failure_policy`
 * and degrades per row: a value that cannot be decrypted becomes `null` with an
 * explicit availability flag rather than throwing or leaking `sw0:` ciphertext.
 * One poison row never aborts the batch and never 500s a health surface.
 *
 * ## Payload minimization
 *
 * The list reads ({@see pending()} / {@see deadLettered()}) return row metadata
 * plus the short `last_error` only — NOT the full evidence envelope, which can be
 * large and carries the most sensitive data. A consumer fetches the decrypted
 * payload for a single row on demand via {@see record()}, mirroring how
 * `swarm:trace` gates full payloads behind `--include-payloads`. This keeps a
 * list surface from over-fetching decrypted audit evidence.
 *
 * Consumers MUST bind this contract, never the `@internal` concrete outbox or
 * the `@internal` `SwarmPersistenceCipher`. The default binding resolves the
 * database-backed outbox when the persistence driver supports it, and a no-op
 * (reporting an empty, unavailable outbox) otherwise — mirroring how
 * {@see AuditOutbox} itself is bound.
 *
 * This contract governs the outbox RETRY BUFFER only. The full audit TRAIL —
 * every evidence record a run emitted — is served by an application's own
 * {@see ReadableSwarmAuditSink} implementation; core's default sink stores
 * nothing, so a trail surface requires the app to bind a readable sink.
 */
interface ReadableAuditOutbox
{
    /**
     * Whether the outbox is backed by a working store. False in cache
     * persistence mode and when the `swarm_audit_outbox` table is missing;
     * a display surface should render an "outbox unavailable" empty state.
     */
    public function isAvailable(): bool;

    /**
     * Rows still pending re-delivery, newest first. Metadata + display-decrypted
     * `last_error` only — call {@see record()} for a row's full payload.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(int $limit = 100): array;

    /**
     * Rows that exhausted `swarm.audit.outbox.max_attempts` and moved to the
     * dead-letter status, newest first. Metadata + display-decrypted `last_error`
     * only — call {@see record()} for a row's full payload.
     *
     * @return array<int, array<string, mixed>>
     */
    public function deadLettered(int $limit = 100): array;

    /**
     * A single outbox row by id, including its full display-decrypted evidence
     * `payload` (with a `payload_available` flag), or null when unknown. This is
     * the on-demand detail read the list methods deliberately omit the payload
     * from.
     *
     * @return array<string, mixed>|null
     */
    public function record(int $id): ?array;

    /**
     * A non-mutating health summary: row counts by status, the number of rows
     * currently reserved by a drainer, and the oldest pending timestamp. No
     * decryption — counts and timestamps only.
     *
     * @return array{available: bool, pending: int, dead_letter: int, reserved: int, oldest_pending_at: ?string}
     */
    public function healthSummary(): array;
}
