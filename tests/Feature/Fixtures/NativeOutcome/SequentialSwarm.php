<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

#[Topology(TopologyEnum::Sequential)]
#[DurableRetry(maxAttempts: 5, nonRetryable: [])]
class SequentialSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new PendingAgent, new FakeWriter];
    }
}
