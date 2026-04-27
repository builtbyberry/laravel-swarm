<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableBranches;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

#[Topology(TopologyEnum::Parallel)]
class FakeRoutedParallelSwarm implements RoutesDurableBranches, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeResearcher,
            new FakeWriter,
        ];
    }

    public function durableBranchQueue(RunContext $context, array $branch): array
    {
        return [
            'connection' => 'branch-connection',
            'queue' => 'branch-queue',
        ];
    }
}
