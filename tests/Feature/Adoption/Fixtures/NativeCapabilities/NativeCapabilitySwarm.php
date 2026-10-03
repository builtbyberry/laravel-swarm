<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use Laravel\Ai\Contracts\Agent;

/**
 * A container-resolvable Swarm whose agent runs a native embedding tool. Because
 * `agents()` reconstructs the agent and its tool by class each time, the swarm
 * survives queue/durable worker re-resolution — the path a background-mode proof
 * needs (the queued job re-resolves this swarm, not a serialized closure).
 */
#[Topology(TopologyEnum::Sequential)]
class NativeCapabilitySwarm implements Swarm
{
    use Runnable;

    /**
     * @return array<int, Agent>
     */
    public function agents(): array
    {
        return [new CapabilityAgent([new EmbedTool])];
    }
}
