<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

readonly class SwarmResumed
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $runId,
        public string $swarmClass,
        public string $topology,
        public array $metadata = [],
        public ?string $executionMode = null,
    ) {}
}
