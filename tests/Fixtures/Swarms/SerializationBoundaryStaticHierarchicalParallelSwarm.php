<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SerializationBoundaryParallelBranchOne;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SerializationBoundaryParallelBranchTwo;

#[Topology(TopologyEnum::StaticHierarchical)]
class SerializationBoundaryStaticHierarchicalParallelSwarm implements Swarm, HasRoutePlan
{
    use Runnable;

    public function agents(): array
    {
        return [
            app(SerializationBoundaryParallelBranchOne::class),
            app(SerializationBoundaryParallelBranchTwo::class),
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'parallel_node',
            'nodes' => [
                'parallel_node' => [
                    'type' => 'parallel',
                    'branches' => ['branch_one', 'branch_two'],
                    'next' => 'finish_node',
                ],
                'branch_one' => [
                    'type' => 'worker',
                    'agent' => SerializationBoundaryParallelBranchOne::class,
                    'prompt' => 'branch-one',
                ],
                'branch_two' => [
                    'type' => 'worker',
                    'agent' => SerializationBoundaryParallelBranchTwo::class,
                    'prompt' => 'branch-two',
                ],
                'finish_node' => [
                    'type' => 'finish',
                    'output_from' => 'branch_one',
                ],
            ],
        ];
    }
}
