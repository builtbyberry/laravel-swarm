<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use RuntimeException;

class FailingStreamEventStore implements StreamEventStore
{
    public function record(string $runId, SwarmStreamEvent $event, int $ttlSeconds): void
    {
        throw new RuntimeException('Replay store failed.');
    }

    public function forget(string $runId): void
    {
        //
    }

    public function events(string $runId): iterable
    {
        return [];
    }
}
