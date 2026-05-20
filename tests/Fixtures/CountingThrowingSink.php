<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use RuntimeException;

/**
 * Test-only audit sink that fails its first N emits, then succeeds.
 *
 * Used to exercise sink-failure paths (handler decisions, outbox routing,
 * retry semantics) without inventing one-off anonymous classes per test.
 */
final class CountingThrowingSink implements SwarmAuditSink
{
    public int $attempts = 0;

    public function __construct(private readonly int $failFirstN = PHP_INT_MAX) {}

    public function emit(string $category, array $payload): void
    {
        $this->attempts++;

        if ($this->attempts <= $this->failFirstN) {
            throw new RuntimeException("sink failure #{$this->attempts}");
        }
    }
}
