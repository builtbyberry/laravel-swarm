<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

class SwarmWaitTimedOut
{
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly string $topology,
        public readonly string $waitName,
        public readonly string $executionMode = 'durable',
    ) {}
}
