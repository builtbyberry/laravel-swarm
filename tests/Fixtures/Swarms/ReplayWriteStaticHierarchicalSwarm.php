<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryRecallAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemorySpyFlakyAgent;

#[Topology(TopologyEnum::StaticHierarchical)]
#[DurableRetry(maxAttempts: 2, backoffSeconds: [60])]
class ReplayWriteStaticHierarchicalSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new MemorySpyFlakyAgent,
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
                    'agent' => MemorySpyFlakyAgent::class,
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
