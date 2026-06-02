<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingWriter;

#[Topology(TopologyEnum::StaticHierarchical)]
class RememberingStaticHierarchicalSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new RememberingWriter,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'writer_node',
            'nodes' => [
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => RememberingWriter::class,
                    'prompt' => 'static-write-task',
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
