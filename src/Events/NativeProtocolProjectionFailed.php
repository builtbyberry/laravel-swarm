<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;

final readonly class NativeProtocolProjectionFailed
{
    public function __construct(
        public string $runId,
        public string $protocol,
        public NativeProtocolProjection $projection,
        public string $reason,
        public int $timestamp,
    ) {}
}
