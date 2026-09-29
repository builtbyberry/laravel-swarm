<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use BuiltByBerry\LaravelSwarm\Events\NativeProtocolProjectionFailed;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;

/** @internal Creates a failure callback without retaining the stream runner that owns the dispatcher. */
final class NativeProtocolFailureReporter
{
    /** @return Closure(string, string, NativeProtocolProjection, string):void */
    public static function callback(Dispatcher $events): Closure
    {
        return static function (string $runId, string $protocol, NativeProtocolProjection $projection, string $reason) use ($events): void {
            $events->dispatch(
                new NativeProtocolProjectionFailed($runId, $protocol, $projection, $reason, SwarmStreamEvent::timestamp()),
            );
        };
    }
}
