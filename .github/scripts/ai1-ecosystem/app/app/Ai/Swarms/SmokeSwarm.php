<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\SmokeAgent;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

final class SmokeSwarm implements Swarm
{
    public function agents(): array
    {
        return [new SmokeAgent];
    }
}
