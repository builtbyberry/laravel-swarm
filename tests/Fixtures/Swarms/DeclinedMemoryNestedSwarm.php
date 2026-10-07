<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\DeclinedMemoryParentAgent;

final class DeclinedMemoryNestedSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new DeclinedMemoryParentAgent];
    }
}
