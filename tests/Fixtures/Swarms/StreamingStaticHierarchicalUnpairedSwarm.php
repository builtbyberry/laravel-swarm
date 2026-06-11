<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StreamingUnpairedToolCallAgent;

/**
 * Single-worker static-hierarchical streaming swarm whose worker emits an
 * unpaired ToolCall. Abandoning its stream at the tool-call event proves the
 * StaticHierarchicalStreamRunner flushes the pending call into the snapshot from
 * its finally (OG1a) — lost under the pre-fix code where the flush ran inside the
 * try, after the foreach.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
class StreamingStaticHierarchicalUnpairedSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new StreamingUnpairedToolCallAgent,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'worker_node',
            'nodes' => [
                'worker_node' => [
                    'type' => 'worker',
                    'agent' => StreamingUnpairedToolCallAgent::class,
                    'prompt' => 'unpaired-task',
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
