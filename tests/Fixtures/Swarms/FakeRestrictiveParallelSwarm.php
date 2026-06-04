<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\PropagationPolicy;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Support\RestrictivePropagationPolicy;

/**
 * Two-agent parallel swarm under the restrictive policy. Two branches keep the
 * durable advance simple (parallel:0, parallel:1, then the synthesis step) while
 * still exercising the parallel runner live.
 */
#[Topology(TopologyEnum::Parallel)]
#[PropagationPolicy(RestrictivePropagationPolicy::class)]
class FakeRestrictiveParallelSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeResearcher,
            new FakeWriter,
        ];
    }
}
