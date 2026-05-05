<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Telemetry;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;

/**
 * Default telemetry sink that discards all records.
 *
 * Replace this binding in your service container to route telemetry to a real sink.
 */
final class NoOpSwarmTelemetrySink implements SwarmTelemetrySink
{
    public function emit(string $category, array $payload): void
    {
        // Intentionally no-op.
    }
}
