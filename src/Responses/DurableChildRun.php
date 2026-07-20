<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

readonly class DurableChildRun
{
    public function __construct(
        public string $parentRunId,
        public string $childRunId,
        public string $childSwarmClass,
        public string $status,
    ) {}
}
