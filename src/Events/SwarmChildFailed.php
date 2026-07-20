<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

readonly class SwarmChildFailed
{
    /**
     * @param  array<string, mixed>|null  $failure
     */
    public function __construct(
        public string $parentRunId,
        public string $childRunId,
        public string $childSwarmClass,
        public ?array $failure = null,
        public string $executionMode = 'durable',
    ) {}
}
