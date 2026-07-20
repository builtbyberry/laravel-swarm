<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

readonly class DurableSignalResult
{
    /**
     * @param  array<string, mixed>|null  $signal
     */
    public function __construct(
        public string $runId,
        public string $name,
        public string $status,
        public bool $accepted,
        public bool $duplicate = false,
        public ?array $signal = null,
        public ?string $swarmClass = null,
    ) {}
}
