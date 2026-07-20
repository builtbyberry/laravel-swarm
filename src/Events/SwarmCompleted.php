<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;

readonly class SwarmCompleted
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, SwarmArtifact>  $artifacts
     */
    public function __construct(
        public string $runId,
        public string $swarmClass,
        public string $topology,
        public ?string $output,
        public int $durationMs,
        public array $metadata = [],
        public array $artifacts = [],
        public ?string $executionMode = null,
    ) {}
}
