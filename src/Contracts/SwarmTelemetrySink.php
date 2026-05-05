<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Receives normalized observability telemetry payloads emitted by the swarm runtime,
 * queue workers, and stream/broadcast boundaries.
 *
 * Bind this contract in the service container to route telemetry into logs, metrics,
 * tracing adapters, or vendor collectors. The default binding is NoOpSwarmTelemetrySink.
 *
 * Implementations must not throw exceptions that could affect swarm execution — the
 * dispatcher isolates failures per the configured swarm.observability.failure_policy.
 */
interface SwarmTelemetrySink
{
    /**
     * Receive a single telemetry record.
     *
     * @param  array<string, mixed>  $payload  Stable telemetry payload including schema_version,
     *                                         category, occurred_at, and correlation fields.
     */
    public function emit(string $category, array $payload): void;
}
