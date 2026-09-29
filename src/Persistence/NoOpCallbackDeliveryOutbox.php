<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableCallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Enums\CallbackSlot;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\CallbackDrainResult;
use BuiltByBerry\LaravelSwarm\Responses\SwarmTerminalContext;
use Closure;

/**
 * No-op callback-delivery outbox used when the persistence driver does not
 * support database-backed delivery (e.g. the cache driver).
 *
 * register() FAILS CLOSED: terminal then()/catch() callbacks cannot be persisted
 * and delivered without a database, so registering one throws rather than silently
 * dropping the guarantee. settle()/discard()/drain()/deliver() are inert (there is
 * nothing to settle or deliver), and the {@see ReadableCallbackDeliveryOutbox}
 * reads report an empty, unavailable outbox.
 *
 * @internal
 */
class NoOpCallbackDeliveryOutbox implements CallbackDeliveryOutbox, ReadableCallbackDeliveryOutbox
{
    public function register(string $runId, CallbackSlot $slot, Closure $callback): void
    {
        throw new SwarmException(
            'Terminal workflow callbacks require database-backed persistence. Set '
            .'swarm.persistence.driver to "database" and run the package migrations, or listen '
            .'to the SwarmCompleted / SwarmFailed events instead of then()/catch().'
        );
    }

    public function hasFor(string $runId): bool
    {
        return false;
    }

    public function settle(string $runId, SwarmTerminalContext $context): void {}

    public function discard(string $runId): void {}

    public function drain(int $limit = 100): CallbackDrainResult
    {
        return new CallbackDrainResult(0, 0, 0, 0, 0);
    }

    public function deliver(int $id): void {}

    public function isAvailable(): bool
    {
        return false;
    }

    public function assertReady(): void
    {
        throw new SwarmException(
            'Callback delivery outbox is not available with the current persistence driver. '
            .'Switch swarm.persistence.driver to "database" and run the package migrations to enable it.'
        );
    }

    public function pending(int $limit = 100): array
    {
        return [];
    }

    public function deadLettered(int $limit = 100): array
    {
        return [];
    }

    public function healthSummary(): array
    {
        return [
            'available' => false,
            'registered' => 0,
            'pending' => 0,
            'dead_letter' => 0,
            'reserved' => 0,
            'oldest_pending_at' => null,
        ];
    }
}
