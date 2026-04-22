<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

#[Topology(TopologyEnum::Hierarchical)]
class FakeHierarchicalEmptySwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [];
    }

    public function route(string $coordinatorOutput, array $agents, RunContext $context): array
    {
        return [];
    }
}

