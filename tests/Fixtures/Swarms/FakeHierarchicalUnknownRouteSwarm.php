<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

#[Topology(TopologyEnum::Hierarchical)]
class FakeHierarchicalUnknownRouteSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeResearcher,
            new FakeWriter,
        ];
    }

    public function route(string $coordinatorOutput, array $agents, RunContext $context): array
    {
        return [
            [
                'agent_class' => 'App\\Ai\\Agents\\Foo',
                'input' => 'unknown-task',
            ],
        ];
    }
}
