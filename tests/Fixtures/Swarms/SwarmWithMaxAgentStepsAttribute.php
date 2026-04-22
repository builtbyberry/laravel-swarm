<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

#[MaxAgentSteps(4)]
class SwarmWithMaxAgentStepsAttribute implements Swarm
{
    public function agents(): array
    {
        return [];
    }
}
