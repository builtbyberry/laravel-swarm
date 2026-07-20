<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

class DurableChildRun
{
    public function __construct(
        public readonly string $parentRunId,
        public readonly string $childRunId,
        public readonly string $childSwarmClass,
        public readonly string $status,
    ) {}
}
