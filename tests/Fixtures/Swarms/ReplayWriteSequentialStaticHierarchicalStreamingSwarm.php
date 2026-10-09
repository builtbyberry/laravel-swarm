<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryRecallAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryWritingFlakyStreamAgent;

#[Topology(TopologyEnum::StaticHierarchical)]
class ReplayWriteSequentialStaticHierarchicalStreamingSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new MemoryWritingFlakyStreamAgent,
            new MemoryRecallAgent,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'writer',
            'nodes' => [
                'writer' => [
                    'type' => 'worker',
                    'agent' => MemoryWritingFlakyStreamAgent::class,
                    'prompt' => 'write-on-retry',
                    'next' => 'reader',
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
