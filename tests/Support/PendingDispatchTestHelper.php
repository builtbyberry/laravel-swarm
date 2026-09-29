<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use Illuminate\Foundation\Bus\PendingDispatch;
use ReflectionProperty;

final class PendingDispatchTestHelper
{
    public static function release(DurableSwarmResponse|QueuedSwarmResponse $response): void
    {
        $property = new ReflectionProperty($response, 'dispatchable');
        $pendingDispatch = $property->getValue($response);
        $property->setValue($response, self::noop());

        unset($pendingDispatch);
        gc_collect_cycles();
    }

    private static function noop(): PendingDispatch
    {
        return new class(new class
        {
            public function handle(): void {}
        }) extends PendingDispatch
        {

            public function __destruct() {}
        };
    }
}
