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
 * A parallel fan-out/join nested inside a bounded loop.
 *
 * gather → parallel(researcher, writer) → join (reviewer, loops back to gather
 * up to 3×). The fan-out must re-run on every iteration; each branch is counted
 * once per pass for budgeting (not double-counted), so the worst case is
 * 3 × {gather, researcher, writer, join} = 12 worker executions.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
#[MaxAgentSteps(20)]
class FakeStaticHierarchicalParallelInLoopSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeEditor,
            new FakeResearcher,
            new FakeWriter,
            new FakeReviewer,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'gather',
            'nodes' => [
                'gather' => [
                    'type' => 'worker',
                    'agent' => FakeEditor::class,
                    'prompt' => 'gather',
                    'next' => 'fan_out',
                ],
                'fan_out' => [
                    'type' => 'parallel',
                    'branches' => ['branch_research', 'branch_write'],
                    'next' => 'join',
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
                'join' => [
                    'type' => 'worker',
                    'agent' => FakeReviewer::class,
                    'prompt' => 'join',
                    'with_outputs' => [
                        'research' => 'branch_research',
                        'draft' => 'branch_write',
                    ],
                    'next' => 'finish',
                    'loop' => [
                        'to' => 'gather',
                        'max_iterations' => 3,
                    ],
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'join',
                ],
            ],
        ];
    }
}
