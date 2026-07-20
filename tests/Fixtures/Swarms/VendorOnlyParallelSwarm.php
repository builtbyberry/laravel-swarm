<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Runners\ParallelRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyWriter;

/**
 * A parallel swarm composed entirely of plain `laravel/ai` agents.
 *
 * Exercises the `instanceof` gates in
 * {@see ParallelRunner}, which rejected
 * vendor-only agents before v0.23.0.
 */
#[Topology(TopologyEnum::Parallel)]
class VendorOnlyParallelSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new VendorOnlyResearcher, new VendorOnlyWriter];
    }
}
