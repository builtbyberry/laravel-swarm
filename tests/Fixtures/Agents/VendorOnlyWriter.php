<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * A second plain `laravel/ai` agent — see {@see VendorOnlyResearcher}.
 *
 * Implements ONLY the vendor contract, never the swarm marker.
 */
class VendorOnlyWriter implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a writer.';
    }
}
