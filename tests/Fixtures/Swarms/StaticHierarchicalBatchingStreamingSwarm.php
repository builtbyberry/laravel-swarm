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
 * A static-hierarchical durable streaming swarm for stream-batching tests: a
 * {@see BatchingStreamEditor} worker, then a plain one, so the first worker's
 * checkpoint is not the run's last.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
#[DurableStreaming]
class StaticHierarchicalBatchingStreamingSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new BatchingStreamEditor('writer'),
            new PlainStreamEditor('editor'),
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'writer_node',
            'nodes' => [
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => BatchingStreamEditor::class,
                    'prompt' => 'static-writer-task',
                    'next' => 'editor_node',
                ],
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => PlainStreamEditor::class,
                    'prompt' => 'static-editor-task',
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'editor_node',
                ],
            ],
        ];
    }
}
