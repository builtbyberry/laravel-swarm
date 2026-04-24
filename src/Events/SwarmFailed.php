<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

use Throwable;

class SwarmFailed
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly string $topology,
        public readonly Throwable $exception,
        public readonly int $durationMs,
        public readonly array $metadata = [],
    ) {}
}
