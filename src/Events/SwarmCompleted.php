<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;

class SwarmCompleted
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, SwarmArtifact>  $artifacts
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly string $output,
        public readonly array $metadata = [],
        public readonly array $artifacts = [],
    ) {}
}
