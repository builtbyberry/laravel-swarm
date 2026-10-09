<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\StreamParallelBranches;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ProcessReplayMemoryWriterA;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SerializationBoundaryParallelBranchTwo;

#[Topology(TopologyEnum::StaticHierarchical)]
#[StreamParallelBranches('concurrent')]
class ProcessReplayStructuredWriteStaticHierarchicalSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ProcessReplayMemoryWriterA,
            new SerializationBoundaryParallelBranchTwo,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'parallel',
            'nodes' => [
                'parallel' => [
                    'type' => 'parallel',
                    'branches' => ['writer', 'stable'],
                    'next' => 'finish',
                ],
                'writer' => [
                    'type' => 'worker',
                    'agent' => ProcessReplayMemoryWriterA::class,
                    'prompt' => 'write:nested',
                ],
                'stable' => [
                    'type' => 'worker',
                    'agent' => SerializationBoundaryParallelBranchTwo::class,
                    'prompt' => 'stable',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'stable',
                ],
            ],
        ];
    }
}
