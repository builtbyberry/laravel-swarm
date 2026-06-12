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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeReviewer;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

/**
 * A diamond: two parallel branches fan in to a single synthesis node, and a
 * reviewer after the synthesis loops back to that synthesis node. The synthesis
 * node is the loop target AND the join point — it must appear exactly once in
 * the durable entry spine so the loop rewinds to the correct occurrence.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
#[MaxAgentSteps(20)]
class FakeStaticHierarchicalDiamondLoopSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeResearcher,
            new FakeWriter,
            new FakeEditor,
            new FakeReviewer,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'fan_out',
            'nodes' => [
                'fan_out' => [
                    'type' => 'parallel',
                    'branches' => ['branch_research', 'branch_write'],
                    'next' => 'synth',
                ],
                'branch_research' => [
                    'type' => 'worker',
                    'agent' => FakeResearcher::class,
                    'prompt' => 'research',
                ],
                'branch_write' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'write',
                ],
                'synth' => [
                    'type' => 'worker',
                    'agent' => FakeEditor::class,
                    'prompt' => 'synthesize',
                    'with_outputs' => [
                        'research' => 'branch_research',
                        'draft' => 'branch_write',
                    ],
                    'next' => 'review',
                ],
                'review' => [
                    'type' => 'worker',
                    'agent' => FakeReviewer::class,
                    'prompt' => 'review',
                    'next' => 'finish',
                    'loop' => [
                        'to' => 'synth',
                        'max_iterations' => 3,
                    ],
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'review',
                ],
            ],
        ];
    }
}
