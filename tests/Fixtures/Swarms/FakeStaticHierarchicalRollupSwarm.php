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
 * researcher -> rollup(digests researcher) -> writer(reads the digest) -> finish.
 *
 * The rollup (a FakeEditor) digests the researcher's output; the writer reads the
 * digest in its place, never the raw research — proving the rollup bounds the
 * downstream context and that the digested generation is sealable mid-run (#289).
 */
#[Topology(TopologyEnum::StaticHierarchical)]
class FakeStaticHierarchicalRollupSwarm implements HasRoutePlan, Swarm
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
            'start_at' => 'researcher_node',
            'nodes' => [
                'researcher_node' => [
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
                        'research' => 'researcher_node',
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
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'writer_node',
                ],
            ],
        ];
    }
}
