<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\StreamParallelBranches;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingWriter;

#[Topology(TopologyEnum::StaticHierarchical)]
#[StreamParallelBranches('concurrent')]
class RememberingStaticHierarchicalConcurrentSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeResearcher,
            new RememberingWriter,
            new FakeEditor,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'parallel_gather',
            'nodes' => [
                'parallel_gather' => [
                    'type' => 'parallel',
                    'branches' => ['researcher_node', 'writer_node'],
                    'next' => 'editor_node',
                ],
                'researcher_node' => [
                    'type' => 'worker',
                    'agent' => FakeResearcher::class,
                    'prompt' => 'concurrent-research-task',
                ],
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => RememberingWriter::class,
                    'prompt' => 'concurrent-write-task',
                ],
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => FakeEditor::class,
                    'prompt' => 'concurrent-synthesize-task',
                    'with_outputs' => [
                        'research' => 'researcher_node',
                        'draft' => 'writer_node',
                    ],
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
