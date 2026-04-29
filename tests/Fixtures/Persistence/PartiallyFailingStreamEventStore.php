<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use RuntimeException;

class PartiallyFailingStreamEventStore implements StreamEventStore
{
    /**
     * @var array<int, SwarmStreamEvent>
     */
    public array $events = [];

    public bool $forgotten = false;

    protected int $attempts = 0;

    public function record(string $runId, SwarmStreamEvent $event, int $ttlSeconds): void
    {
        $this->attempts++;

        if ($this->attempts > 1) {
            throw new RuntimeException('Replay store failed after partial write.');
        }

        $this->events[] = $event;
    }

    public function forget(string $runId): void
    {
        $this->events = [];
        $this->forgotten = true;
    }

    public function events(string $runId): iterable
    {
        yield from $this->events;
    }
}
