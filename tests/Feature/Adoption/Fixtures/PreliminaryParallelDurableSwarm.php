<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\PreliminaryResultAgent;

#[Topology(TopologyEnum::Parallel)]
#[DurableStreaming]
#[DurableRetry(maxAttempts: 2, backoffSeconds: [60])]
class PreliminaryParallelDurableSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new PreliminaryRetryAgent, new PreliminaryResultAgent];
    }
}
