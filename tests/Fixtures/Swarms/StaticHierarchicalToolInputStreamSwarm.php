<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ToolInputStreamEditor;

/** The static-hierarchical twin of {@see ToolInputStreamSwarm}, for chain parity. */
#[Topology(TopologyEnum::StaticHierarchical)]
class StaticHierarchicalToolInputStreamSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new ToolInputStreamEditor];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'editor_node',
            'nodes' => [
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => ToolInputStreamEditor::class,
                    'prompt' => 'tool-input-task',
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
