<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyWriter;

/**
 * A hierarchical swarm whose coordinator AND worker are plain `laravel/ai`
 * agents.
 *
 * Exercises the coordinator path through
 * {@see HierarchicalRoutePlanner} and the
 * worker `instanceof` gates in
 * {@see HierarchicalRunner}.
 */
#[Topology(TopologyEnum::Hierarchical)]
class VendorOnlyHierarchicalSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new VendorOnlyCoordinator, new VendorOnlyWriter];
    }
}
