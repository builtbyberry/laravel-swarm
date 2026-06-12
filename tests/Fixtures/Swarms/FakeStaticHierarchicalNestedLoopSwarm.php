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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeReviewer;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

/**
 * Two-level nested bounded loop.
 *
 * Inner loop: writer (draft) → editor refines up to 3× back to the writer.
 * Outer loop: after the inner loop settles, the reviewer reviews and loops the
 * whole inner body back to the writer up to 2×.
 *
 * Worst case: writer/editor each run 2 (outer) × 3 (inner) = 6 times, the
 * reviewer 2 times. The inner counter must reset on every outer back-edge so the
 * inner loop fires its full count on each outer pass.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
#[MaxAgentSteps(20)]
class FakeStaticHierarchicalNestedLoopSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeWriter,
            new FakeEditor,
            new FakeReviewer,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'inner_body',
            'nodes' => [
                'inner_body' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'draft',
                    'next' => 'inner_loop',
                ],
                'inner_loop' => [
                    'type' => 'worker',
                    'agent' => FakeEditor::class,
                    'prompt' => 'refine',
                    'next' => 'outer_loop',
                    'loop' => [
                        'to' => 'inner_body',
                        'max_iterations' => 3,
                    ],
                ],
                'outer_loop' => [
                    'type' => 'worker',
                    'agent' => FakeReviewer::class,
                    'prompt' => 'review',
                    'next' => 'finish',
                    'loop' => [
                        'to' => 'inner_body',
                        'max_iterations' => 2,
                    ],
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'outer_loop',
                ],
            ],
        ];
    }
}
