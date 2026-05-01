<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

class DurableWaitOutcome
{
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly mixed $payload = null,
        public readonly bool $timedOut = false,
    ) {}
}
