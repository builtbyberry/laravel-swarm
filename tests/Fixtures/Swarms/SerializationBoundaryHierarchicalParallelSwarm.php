<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SerializationBoundaryParallelBranchOne;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SerializationBoundaryParallelBranchTwo;

#[Topology(TopologyEnum::Hierarchical)]
class SerializationBoundaryHierarchicalParallelSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeHierarchicalCoordinator,
            new SerializationBoundaryParallelBranchOne,
            new SerializationBoundaryParallelBranchTwo,
        ];
    }
}
