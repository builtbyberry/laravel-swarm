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
 * A bounded loop with a rollup in its body (#289 R7):
 *
 *   research -> rollup(digests research) -> writer(loop back to research, max 2).
 *
 * Each iteration digests THAT iteration's fresh research output by targeting its
 * current step-end — never the once-only node-open event, so a prior iteration's
 * seal barrier can never make the next iteration throw SealedCausalWindowException.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
class FakeStaticHierarchicalRollupLoopSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeResearcher,
            new FakeEditor,
            new FakeWriter,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'research_node',
            'nodes' => [
                'research_node' => [
                    'type' => 'worker',
                    'agent' => FakeResearcher::class,
                    'prompt' => 'research-task',
                    'next' => 'rollup_node',
                ],
                'rollup_node' => [
                    'type' => 'rollup',
                    'agent' => FakeEditor::class,
                    'prompt' => 'digest-task',
                    'with_outputs' => [
                        'research' => 'research_node',
                    ],
                    'next' => 'writer_node',
                ],
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'write-task',
                    'with_outputs' => [
                        'digest' => 'rollup_node',
                    ],
                    'next' => 'finish',
                    'loop' => [
                        'to' => 'research_node',
                        'max_iterations' => 2,
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
