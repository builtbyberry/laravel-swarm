<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

readonly class DurableWaitOutcome
{
    public function __construct(
        public string $name,
        public string $status,
        public mixed $payload = null,
        public bool $timedOut = false,
    ) {}
}
