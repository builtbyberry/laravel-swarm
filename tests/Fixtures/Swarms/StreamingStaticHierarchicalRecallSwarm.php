<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StreamingRecallAgent;

/**
 * Single sequential worker node that streams the real Recall tool, so a
 * crash-resume of a static-hierarchical streaming run replays the frozen value
 * (OG1b, sequential-node resume). Index 0 is the streamed worker step.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
class StreamingStaticHierarchicalRecallSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new StreamingRecallAgent,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'recall_node',
            'nodes' => [
                'recall_node' => [
                    'type' => 'worker',
                    'agent' => StreamingRecallAgent::class,
                    'prompt' => 'recall-task',
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'recall_node',
                ],
            ],
        ];
    }
}
