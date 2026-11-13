<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\BatchingStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;

/**
 * A static-hierarchical fan-out for stream-batching tests: one branch is a
 * {@see BatchingStreamEditor}, and a synthesis worker after the join keeps the
 * post-join checkpoint (which seals the branches) non-terminal.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
#[DurableStreaming]
class BatchingFanOutStreamingSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new BatchingStreamEditor('research'),
            new PlainStreamEditor('write'),
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'fan_out',
            'nodes' => [
                'fan_out' => [
                    'type' => 'parallel',
                    'branches' => ['research_node', 'write_node'],
                    'next' => 'synthesis_node',
                ],
                'research_node' => [
                    'type' => 'worker',
                    'agent' => BatchingStreamEditor::class,
                    'prompt' => 'fan-out-research-task',
                ],
                'write_node' => [
                    'type' => 'worker',
                    'agent' => PlainStreamEditor::class,
                    'prompt' => 'fan-out-write-task',
                ],
                'synthesis_node' => [
                    'type' => 'worker',
                    'agent' => PlainStreamEditor::class,
                    'prompt' => 'fan-out-synthesis-task',
                    'with_outputs' => [
                        'research' => 'research_node',
                        'draft' => 'write_node',
                    ],
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'synthesis_node',
                ],
            ],
        ];
    }
}
