<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

readonly class SwarmWaitTimedOut
{
    public function __construct(
        public string $runId,
        public string $swarmClass,
        public string $topology,
        public string $waitName,
        public string $executionMode = 'durable',
    ) {}
}
