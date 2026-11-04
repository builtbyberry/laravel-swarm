<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use RuntimeException;

/**
 * The database store, except that recording a recovery for one run throws: the
 * shape of a recovery marker write that fails after the release it follows has
 * already committed.
 */
final class MarkerFailingDurableRunStore extends DatabaseDurableRunStore
{
    public ?string $failMarkerFor = null;

    public function markRecoveryDispatched(string $runId): void
    {
        if ($runId === $this->failMarkerFor) {
            throw new RuntimeException('recovery marker unavailable');
        }

        parent::markRecoveryDispatched($runId);
    }
}
