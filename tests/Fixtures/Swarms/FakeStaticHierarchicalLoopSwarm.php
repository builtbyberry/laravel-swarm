<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

/**
 * A bounded loop: the writer drafts once, then the editor refines that draft up
 * to three times before control falls through to the finish node.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
#[MaxAgentSteps(8)]
class FakeStaticHierarchicalLoopSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeWriter,
            new FakeEditor,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'writer_node',
            'nodes' => [
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'draft-task',
                    'next' => 'editor_node',
                ],
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => FakeEditor::class,
                    'prompt' => 'refine-task',
                    'with_outputs' => [
                        'draft' => 'writer_node',
                    ],
                    'next' => 'finish',
                    'loop' => [
                        'to' => 'editor_node',
                        'max_iterations' => 3,
                    ],
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'editor_node',
                ],
            ],
        ];
    }
}
