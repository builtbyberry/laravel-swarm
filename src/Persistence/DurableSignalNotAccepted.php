<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use RuntimeException;

/**
 * Rolls a signal acceptance back to its savepoint inside DatabaseDurableRunStore::acceptSignal()
 * when nothing is to be accepted: no single open wait of that name, or a duplicate
 * idempotency key. Never leaves the store.
 *
 * @internal
 */
final class DurableSignalNotAccepted extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $signal  the existing signal for a duplicate key; null when no wait was closed
     */
    public function __construct(public readonly ?array $signal)
    {
        parent::__construct('Durable signal not accepted.');
    }
}
