<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

/** @internal */
final class NativeProtocolProjectionException extends SwarmException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
