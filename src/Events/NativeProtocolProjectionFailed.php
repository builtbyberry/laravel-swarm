<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events;

use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;

final readonly class NativeProtocolProjectionFailed
{
    /**
     * @param  string  $reason  Stable bounded failure category documented in docs/native-chat-protocols.md.
     */
    public function __construct(
        public string $runId,
        public string $protocol,
        public NativeProtocolProjection $projection,
        public string $reason,
        public int $timestamp,
    ) {}
}
