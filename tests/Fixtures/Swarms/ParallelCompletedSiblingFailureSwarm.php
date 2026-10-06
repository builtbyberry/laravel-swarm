<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitingAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ParallelLiveStreamReleasedFailureBranch;

#[Topology(TopologyEnum::Parallel)]
final class ParallelCompletedSiblingFailureSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new CitingAgent, new ParallelLiveStreamReleasedFailureBranch];
    }
}
