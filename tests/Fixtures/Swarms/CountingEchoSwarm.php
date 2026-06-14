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
 * Two-step sequential swarm for #202 multi-step resume tests: a non-final
 * counter-dependent primer feeds the final streaming echo agent. The final
 * step's streamed text therefore equals the primer's output, so a resumed run
 * surfaces whether the primer's output was rehydrated (`primed-1`) or recomputed
 * (`primed-2`).
 */
#[Topology(TopologyEnum::Sequential)]
class CountingEchoSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new CountingPrimerAgent,
            new EchoStreamingAgent,
        ];
    }
}
