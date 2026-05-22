<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\MemoryReplay;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemorySpyFlakyAgent;

#[Topology(TopologyEnum::Sequential)]
#[DurableRetry(maxAttempts: 2, backoffSeconds: [60])]
#[MemoryReplay(mode: ReplayMode::FreshExecution)]
class FreshExecutionReplaySwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new MemorySpyFlakyAgent,
        ];
    }
}
