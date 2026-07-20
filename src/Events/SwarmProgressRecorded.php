<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

readonly class SwarmProgressRecorded
{
    /**
     * @param  array<string, mixed>  $progress
     */
    public function __construct(
        public string $runId,
        public ?string $branchId,
        public array $progress,
        public string $executionMode = 'durable',
        public ?string $swarmClass = null,
        public ?string $topology = null,
    ) {}
}
