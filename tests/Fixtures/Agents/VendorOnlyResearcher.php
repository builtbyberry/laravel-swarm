<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * A plain `laravel/ai` agent, written exactly as `laravel/ai` documents it.
 *
 * Deliberately implements ONLY the vendor contract — NOT
 * {@see \BuiltByBerry\LaravelSwarm\Contracts\Agent}. This is the shape a
 * consumer brings when they already have Laravel AI agents and want to run them
 * through a swarm, and it is the shape Swarm rejected before v0.23.0.
 *
 * Do not "fix" this class by adding the swarm marker — that would delete the
 * regression coverage it exists to provide.
 */
class VendorOnlyResearcher implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a researcher.';
    }
}
