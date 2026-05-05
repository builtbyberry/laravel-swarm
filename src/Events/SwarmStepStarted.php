<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

class SwarmStepStarted
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly int $index,
        public readonly string $agentClass,
        public readonly string $input,
        public readonly array $metadata = [],
        public readonly ?string $topology = null,
        public readonly ?string $executionMode = null,
    ) {}
}
