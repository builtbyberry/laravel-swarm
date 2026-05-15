<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

/**
 * Plan: sequential worker A (FakeResearcher) → parallel group [B (FakeWriter), C (FakeEditor)] → finish
 * Used to test streaming behaviour in mixed sequential+parallel plans.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
class FakeStaticHierarchicalStreamMixedSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeResearcher,
            new FakeWriter,
            new FakeEditor,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'researcher_node',
            'nodes' => [
                'researcher_node' => [
                    'type' => 'worker',
                    'agent' => FakeResearcher::class,
                    'prompt' => 'stream-research-task',
                    'next' => 'parallel_branches',
                ],
                'parallel_branches' => [
                    'type' => 'parallel',
                    'branches' => ['writer_node', 'editor_node'],
                    'next' => 'finish',
                ],
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'stream-write-task',
                    'with_outputs' => [
                        'research' => 'researcher_node',
                    ],
                ],
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => FakeEditor::class,
                    'prompt' => 'stream-edit-task',
                    'with_outputs' => [
                        'research' => 'researcher_node',
                    ],
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'writer_node',
                ],
            ],
        ];
    }
}
