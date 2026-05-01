<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

class SwarmProgressRecorded
{
    /**
     * @param  array<string, mixed>  $progress
     */
    public function __construct(
        public readonly string $runId,
        public readonly ?string $branchId,
        public readonly array $progress,
        public readonly string $executionMode = 'durable',
    ) {}
}
