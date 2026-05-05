<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;

/**
 * Default audit sink that discards all evidence.
 *
 * Replace this binding in your service container to route evidence to a real sink.
 *
 * @example
 *   $this->app->bind(\BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink::class,
 *       MyAppAuditSink::class);
 */
final class NoOpSwarmAuditSink implements SwarmAuditSink
{
    public function emit(string $category, array $payload): void
    {
        // Intentionally no-op.
    }
}
