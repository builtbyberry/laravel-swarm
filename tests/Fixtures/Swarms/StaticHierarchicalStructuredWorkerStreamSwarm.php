<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StructuredOutputWorker;

/**
 * A static-hierarchical swarm whose single worker node declares structured output —
 * the live `stream()` guard target on the shared `drivePlanNodes` worker path (#321).
 */
#[Topology(TopologyEnum::StaticHierarchical)]
class StaticHierarchicalStructuredWorkerStreamSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new StructuredOutputWorker,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'worker_node',
            'nodes' => [
                'worker_node' => [
                    'type' => 'worker',
                    'agent' => StructuredOutputWorker::class,
                    'prompt' => 'structured-worker-task',
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'worker_node',
                ],
            ],
        ];
    }
}
