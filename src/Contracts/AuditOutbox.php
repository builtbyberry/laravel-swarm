<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;

/**
 * Persisted retry surface for audit evidence that failed to emit through
 * the bound SwarmAuditSink.
 *
 * The dispatcher routes records here when the SinkFailureHandler returns
 * SinkFailureDecision::Queue or ::DeadLetter. The swarm:relay --type=audit
 * lane drains the outbox by replaying records through the bound sink.
 *
 * Pending records are re-claimable after the reservation timeout; records
 * that exceed swarm.audit.outbox.max_attempts move to the dead-letter
 * status and stop being re-claimed.
 */
interface AuditOutbox
{
    /**
     * Persist a failed audit record for later retry through the bound sink.
     *
     * @param  array<string, mixed>  $payload  The fully enriched evidence
     *                                         envelope (schema_version,
     *                                         category, occurred_at, and
     *                                         category-specific fields).
     * @param  bool  $deadLetter  When true, persist directly to the
     *                            dead-letter status without retry.
     */
    public function enqueue(string $category, array $payload, bool $deadLetter = false): void;

    /**
     * Claim pending records and re-attempt emission through the bound sink.
     *
     * On successful emit, the record is deleted. On transient failure, the
     * attempt count increments and the reservation is released. After
     * swarm.audit.outbox.max_attempts the row's status moves to 'dead_letter'.
     */
    public function drain(int $limit = 100): AuditDrainResult;

    /**
     * Whether the outbox is backed by a working store. Returns false in
     * cache persistence mode and when the swarm_audit_outbox table is
     * missing. Dispatcher uses this to decide whether to enqueue or fall
     * back to log-and-swallow.
     */
    public function isAvailable(): bool;

    public function assertReady(): void;
}
