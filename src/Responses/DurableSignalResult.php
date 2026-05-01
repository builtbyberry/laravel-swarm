<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

class DurableSignalResult
{
    /**
     * @param  array<string, mixed>|null  $signal
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $name,
        public readonly string $status,
        public readonly bool $accepted,
        public readonly bool $duplicate = false,
        public readonly ?array $signal = null,
    ) {}
}
