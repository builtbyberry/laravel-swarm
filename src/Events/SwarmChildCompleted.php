<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

class SwarmChildCompleted
{
    public function __construct(
        public readonly string $parentRunId,
        public readonly string $childRunId,
        public readonly string $childSwarmClass,
        public readonly string $executionMode = 'durable',
    ) {}
}
