<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

readonly class SwarmChildCompleted
{
    public function __construct(
        public string $parentRunId,
        public string $childRunId,
        public string $childSwarmClass,
        public string $executionMode = 'durable',
    ) {}
}
