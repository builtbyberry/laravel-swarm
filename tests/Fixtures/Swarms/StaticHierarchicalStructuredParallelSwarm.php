<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StructuredOutputWorker;

/**
 * A static-hierarchical swarm whose parallel node runs a structured-output
 * branch, for the structured response text fallback on concurrent branches.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
class StaticHierarchicalStructuredParallelSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new StructuredOutputWorker,
            new FakeWriter,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'parallel_node',
            'nodes' => [
                'parallel_node' => [
                    'type' => 'parallel',
                    'branches' => ['structured_node', 'writer_node'],
                    'next' => 'finish',
                ],
                'structured_node' => [
                    'type' => 'worker',
                    'agent' => StructuredOutputWorker::class,
                    'prompt' => 'structured-branch',
                ],
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'writer-branch',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'structured_node',
                ],
            ],
        ];
    }
}
