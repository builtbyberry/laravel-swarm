<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Receives normalized audit evidence payloads emitted by the swarm runtime, operator
 * commands, webhooks, and durable infrastructure.
 *
 * Bind this contract in the service container to route evidence into an append-only
 * store, SIEM export, queue listener, or object-storage archive.
 *
 * The default binding is NoOpSwarmAuditSink, which discards all evidence.
 * Implementations must be idempotent where possible and must not throw exceptions
 * that could affect swarm execution — the dispatcher isolates failures per the
 * configured swarm.audit.failure_policy.
 */
interface SwarmAuditSink
{
    /**
     * Receive a single audit evidence record.
     *
     * @param  array<string, mixed>  $payload  Stable evidence payload including schema_version,
     *                                         category, occurred_at, and correlation fields.
     */
    public function emit(string $category, array $payload): void;
}
