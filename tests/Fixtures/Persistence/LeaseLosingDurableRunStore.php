<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence;

use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;

/**
 * The database store, except that failing one run throws LostDurableLeaseException:
 * the shape of a durable lease taken and then lost before the failure is written.
 */
final class LeaseLosingDurableRunStore extends DatabaseDurableRunStore
{
    public ?string $loseLeaseOnFailFor = null;

    /**
     * @param  array<string, mixed>|null  $failure
     */
    public function markFailed(string $runId, string $executionToken, ?array $failure = null): void
    {
        if ($runId === $this->loseLeaseOnFailFor) {
            throw new LostDurableLeaseException("Durable run [{$runId}] lost its lease before the failure was written.");
        }

        parent::markFailed($runId, $executionToken, $failure);
    }
}
