<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Concerns\HasSwarmMemoryTools;
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * Test agent that pulls the Swarm memory tools in via the
 * {@see HasSwarmMemoryTools} concern, so a test can assert config-gated
 * registration end to end.
 */
class MemoryToolAgent implements Agent, HasTools
{
    use HasSwarmMemoryTools, Promptable;

    public function instructions(): string
    {
        return 'You are a test agent with Swarm memory tools.';
    }

    /**
     * @return iterable<int, object>
     */
    public function tools(): iterable
    {
        return $this->swarmMemoryTools();
    }
}
