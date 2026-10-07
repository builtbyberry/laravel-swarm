<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\DeclinedMemoryAgent;

#[Topology(TopologyEnum::StaticHierarchical)]
final class DeclinedMemoryStaticHierarchicalSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new DeclinedMemoryAgent];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'memory',
            'nodes' => [
                'memory' => [
                    'type' => 'worker',
                    'agent' => DeclinedMemoryAgent::class,
                    'prompt' => 'Exercise memory.',
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'memory',
                ],
            ],
        ];
    }
}
