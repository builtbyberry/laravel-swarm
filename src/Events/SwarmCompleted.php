<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;

class SwarmCompleted
{
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly SwarmResponse $response,
    ) {}
}
