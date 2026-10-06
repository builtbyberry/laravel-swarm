<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ParallelLiveStreamFailingBranch;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ParallelLiveStreamWaitingBranch;

#[Topology(TopologyEnum::Parallel)]
final class ParallelLiveStreamFailureSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new ParallelLiveStreamFailingBranch, new ParallelLiveStreamWaitingBranch];
    }
}
