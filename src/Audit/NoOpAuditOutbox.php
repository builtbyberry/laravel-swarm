<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;

/**
 * No-op audit outbox used when the persistence driver does not support
 * database-backed retry persistence (e.g. cache driver).
 *
 * enqueue() silently discards. drain() reports zero work. isAvailable()
 * returns false so the dispatcher knows to degrade Queue/DeadLetter
 * decisions to log-and-swallow rather than calling enqueue here.
 *
 * @internal
 */
class NoOpAuditOutbox implements AuditOutbox
{
    public function enqueue(string $category, array $payload, bool $deadLetter = false): void {}

    public function drain(int $limit = 100): AuditDrainResult
    {
        return new AuditDrainResult(0, 0, 0, 0, 0);
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function assertReady(): void
    {
        throw new SwarmException(
            'Audit outbox is not available with the current persistence driver. '
            .'Switch swarm.persistence.driver to "database" and run the package migrations to enable it.'
        );
    }
}
