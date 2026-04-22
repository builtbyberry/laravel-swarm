<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

class SwarmWithoutTopologyAttribute implements Swarm
{
    public function agents(): array
    {
        return [];
    }
}
