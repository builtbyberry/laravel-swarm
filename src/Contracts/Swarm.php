<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use Laravel\Ai\Contracts\Agent;

interface Swarm
{
    /**
     * Get the agents that participate in this swarm.
     *
     * @return array<int, Agent>
     */
    public function agents(): array;
}
