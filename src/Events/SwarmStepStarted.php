<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

readonly class SwarmStepStarted
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $runId,
        public string $swarmClass,
        public int $index,
        public string $agentClass,
        public ?string $input,
        public array $metadata = [],
        public ?string $topology = null,
        public ?string $executionMode = null,
    ) {}
}
