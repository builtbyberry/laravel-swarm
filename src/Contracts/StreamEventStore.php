<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;

interface StreamEventStore
{
    public function record(string $runId, SwarmStreamEvent $event, int $ttlSeconds): void;

    public function forget(string $runId): void;

    /**
     * @return iterable<int, SwarmStreamEvent>
     */
    public function events(string $runId): iterable;
}
