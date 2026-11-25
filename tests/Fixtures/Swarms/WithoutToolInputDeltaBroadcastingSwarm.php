<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolInputDelta;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ToolInputStreamEditor;
use Laravel\Ai\Attributes\WithoutBroadcasting;

/** {@see ToolInputStreamSwarm}, suppressing the chatty tool-input deltas from broadcast only. */
#[Topology(TopologyEnum::Sequential)]
#[WithoutBroadcasting(SwarmToolInputDelta::class)]
class WithoutToolInputDeltaBroadcastingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new ToolInputStreamEditor];
    }
}
