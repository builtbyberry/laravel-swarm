<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

class SwarmWaiting
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly string $topology,
        public readonly string $waitName,
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
        public readonly string $executionMode = 'durable',
    ) {}
}
