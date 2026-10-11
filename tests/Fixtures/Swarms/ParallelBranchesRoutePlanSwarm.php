<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ParallelBranchesRoutePlanCoordinator;

#[Topology(TopologyEnum::Hierarchical)]
class ParallelBranchesRoutePlanSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ParallelBranchesRoutePlanCoordinator,
            new FakeWriter,
            new FakeEditor,
            new FakeResearcher,
        ];
    }
}
