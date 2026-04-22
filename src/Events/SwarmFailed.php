<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

use Throwable;

class SwarmFailed
{
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly Throwable $exception,
    ) {}
}
