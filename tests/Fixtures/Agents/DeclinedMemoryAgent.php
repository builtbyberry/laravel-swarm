<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Concerns\HasSwarmMemoryTools;
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

final class DeclinedMemoryAgent implements Agent, HasTools
{
    use HasSwarmMemoryTools, Promptable;

    public function instructions(): string
    {
        return 'Exercise the Swarm memory tools.';
    }

    public function tools(): iterable
    {
        return [...$this->swarmMemoryTools(agentScope: true)];
    }
}
