<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

class SwarmStarted
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly string $topology,
        public readonly string $input,
        public readonly array $metadata = [],
        public readonly ?string $executionMode = null,
    ) {}
}
