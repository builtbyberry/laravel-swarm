<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\DefinesGuardrails;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ParallelLiveStreamBranchOne;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ParallelLiveStreamBranchTwo;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksStepWhenIndex;

#[Topology(TopologyEnum::Parallel)]
final class ParallelLiveStreamGuardedSwarm implements DefinesGuardrails, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new ParallelLiveStreamBranchOne, new ParallelLiveStreamBranchTwo];
    }

    public function guardrails(): array
    {
        return [new BlocksStepWhenIndex(1)];
    }
}
