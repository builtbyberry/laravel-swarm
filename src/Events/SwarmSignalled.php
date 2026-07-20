<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

readonly class SwarmSignalled
{
    public function __construct(
        public string $runId,
        public string $swarmClass,
        public string $topology,
        public string $signalName,
        public bool $accepted,
        public string $executionMode = 'durable',
    ) {}
}
