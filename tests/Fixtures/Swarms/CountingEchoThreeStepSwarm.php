<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\CountingPrimerAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\EchoStreamingAgent;

/**
 * Three-step sequential swarm for #202: TWO non-final counter-dependent primers
 * feed the final streaming echo agent. On a crash mid-final-stream both
 * non-final steps must be skipped on resume (their shared invocation counter
 * stays at 2, not 4), and the echoed downstream prompt must be the rehydrated
 * `primed-2`.
 */
#[Topology(TopologyEnum::Sequential)]
class CountingEchoThreeStepSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new CountingPrimerAgent,
            new CountingPrimerAgent,
            new EchoStreamingAgent,
        ];
    }
}
