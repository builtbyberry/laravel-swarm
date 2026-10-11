<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\StreamParallelBranches;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryRecallAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ProcessReplayMemoryWriterA;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ProcessReplayMemoryWriterB;

#[Topology(TopologyEnum::StaticHierarchical)]
#[StreamParallelBranches('concurrent')]
class ProcessReplayWriteOrderStaticHierarchicalSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ProcessReplayMemoryWriterA,
            new ProcessReplayMemoryWriterB,
            new MemoryRecallAgent,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'parallel',
            'nodes' => [
                'parallel' => [
                    'type' => 'parallel',
                    'branches' => ['first_writer', 'second_writer'],
                    'next' => 'reader',
                ],
                'first_writer' => [
                    'type' => 'worker',
                    'agent' => ProcessReplayMemoryWriterA::class,
                    'prompt' => 'write:first',
                ],
                'second_writer' => [
                    'type' => 'worker',
                    'agent' => ProcessReplayMemoryWriterB::class,
                    'prompt' => 'write:second',
                ],
                'reader' => [
                    'type' => 'worker',
                    'agent' => MemoryRecallAgent::class,
                    'prompt' => 'read-retry-write',
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'reader',
                ],
            ],
        ];
    }
}
