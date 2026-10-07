<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\AgentTool;

final class DeclinedMemoryParentAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Delegate the task to the memory child.';
    }

    public function tools(): iterable
    {
        return [new AgentTool(new DeclinedMemoryAgent)];
    }
}
