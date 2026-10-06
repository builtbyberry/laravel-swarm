<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Testing\FakePendingDispatch;
use ReflectionProperty;

final class PendingDispatchTestHelper
{
    /**
     * Dispatch the real pending job now and detach it from the response.
     *
     * The inert replacement prevents the response destructor from dispatching
     * the same job again after the test container has moved to another test.
     */
    public static function dispatchAndDetach(DurableSwarmResponse|QueuedSwarmResponse $response): void
    {
        $property = new ReflectionProperty($response, 'dispatchable');
        $pendingDispatch = $property->getValue($response);
        $property->setValue($response, new FakePendingDispatch);

        unset($pendingDispatch);
    }
}
