<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\EchoStreamingAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StreamingRememberAgent;

/**
 * Two-node streaming static-hierarchical plan for the structural node grammar
 * (#284). The first node — `decider_node`, marked with `node_role: coordinator`
 * — streams a text-delta deliberation, then routes forward to its decided child
 * `child_node`. The streamed run therefore appends, in causal order:
 * node.opened(decider) → its deliberation deltas (tagged with the decider's id)
 * → node.children_decided(child) → node.closed(decider) → node.opened(child) …
 */
#[Topology(TopologyEnum::StaticHierarchical)]
class StreamingStaticHierarchicalDeciderSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new EchoStreamingAgent,
            new StreamingRememberAgent,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'decider_node',
            'nodes' => [
                'decider_node' => [
                    'type' => 'worker',
                    'agent' => EchoStreamingAgent::class,
                    'prompt' => 'deliberate then decide',
                    'metadata' => ['node_role' => 'coordinator'],
                    'next' => 'child_node',
                ],
                'child_node' => [
                    'type' => 'worker',
                    'agent' => StreamingRememberAgent::class,
                    'prompt' => 'do the decided subtask',
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'child_node',
                ],
            ],
        ];
    }
}
