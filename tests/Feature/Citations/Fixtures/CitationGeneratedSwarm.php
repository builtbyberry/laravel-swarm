<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;

#[Topology(TopologyEnum::Hierarchical)]
class CitationGeneratedSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new FakeHierarchicalCoordinator, new CitingAgent, new OtherCitingAgent];
    }
}
