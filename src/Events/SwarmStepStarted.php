<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

class SwarmStepStarted
{
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly int $index,
        public readonly string $agentClass,
        public readonly string $input,
        public readonly RunContext $context,
    ) {}
}
