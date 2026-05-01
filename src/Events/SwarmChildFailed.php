<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

class SwarmChildFailed
{
    /**
     * @param  array<string, mixed>|null  $failure
     */
    public function __construct(
        public readonly string $parentRunId,
        public readonly string $childRunId,
        public readonly string $childSwarmClass,
        public readonly ?array $failure = null,
        public readonly string $executionMode = 'durable',
    ) {}
}
