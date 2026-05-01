<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

class SwarmSignalled
{
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly string $topology,
        public readonly string $signalName,
        public readonly bool $accepted,
        public readonly string $executionMode = 'durable',
    ) {}
}
