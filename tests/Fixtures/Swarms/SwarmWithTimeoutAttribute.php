<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Timeout;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

#[Timeout(42)]
class SwarmWithTimeoutAttribute implements Swarm
{
    public function agents(): array
    {
        return [];
    }
}
