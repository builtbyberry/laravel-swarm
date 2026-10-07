<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Concerns\HasSwarmMemoryTools;
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

final class SecondDeclinedMemoryAgent implements Agent, HasTools
{
    use HasSwarmMemoryTools, Promptable;

    public function instructions(): string
    {
        return 'Store a value in Swarm memory.';
    }

    public function tools(): iterable
    {
        return [...$this->swarmMemoryTools()];
    }
}
