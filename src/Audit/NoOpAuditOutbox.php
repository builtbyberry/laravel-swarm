<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;

/**
 * No-op audit outbox used when the persistence driver does not support
 * database-backed retry persistence (e.g. cache driver).
 *
 * enqueue() silently discards. drain() reports zero work. isAvailable()
 * returns false so the dispatcher knows to degrade Queue/DeadLetter
 * decisions to log-and-swallow rather than calling enqueue here. The
 * {@see ReadableAuditOutbox} health reads report an empty, unavailable
 * outbox so a display consumer renders a clean "unavailable" empty state.
 *
 * @internal
 */
class NoOpAuditOutbox implements AuditOutbox, ReadableAuditOutbox
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

    public function pending(int $limit = 100): array
    {
        return [];
    }

    public function deadLettered(int $limit = 100): array
    {
        return [];
    }

    public function record(int $id): ?array
    {
        return null;
    }

    public function healthSummary(): array
    {
        return [
            'available' => false,
            'pending' => 0,
            'dead_letter' => 0,
            'reserved' => 0,
            'oldest_pending_at' => null,
        ];
    }

    public function assertReady(): void
    {
        throw new SwarmException(
            'Audit outbox is not available with the current persistence driver. '
            .'Switch swarm.persistence.driver to "database" and run the package migrations to enable it.'
        );
    }
}
