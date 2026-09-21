<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing;

use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * An inert dispatch for recorded Swarm intent, with no executable job.
 *
 * @internal
 *
 * @see PendingDispatch
 */
class FakePendingDispatch extends PendingDispatch
{
    public function __construct()
    {
        parent::__construct(new class
        {
            /**
             * Discard job configuration without retaining or executing it.
             *
             * @param  array<int, mixed>  $arguments
             */
            public function __call(string $method, array $arguments): void {}
        });
    }

    public function __destruct() {}
}
