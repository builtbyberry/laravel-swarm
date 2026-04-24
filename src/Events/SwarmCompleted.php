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
        public readonly string $topology,
        public readonly string $output,
        public readonly int $durationMs,
        public readonly array $metadata = [],
        public readonly array $artifacts = [],
        public readonly ?string $executionMode = null,
    ) {}
}
