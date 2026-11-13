<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\BatchingStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;

/**
 * A generated-hierarchical durable streaming swarm for stream-batching tests: the
 * faked coordinator routes a {@see BatchingStreamEditor} worker, then a plain one.
 */
#[Topology(TopologyEnum::Hierarchical)]
#[DurableStreaming]
class HierarchicalBatchingStreamingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeHierarchicalCoordinator,
            new BatchingStreamEditor('writer'),
            new PlainStreamEditor('editor'),
        ];
    }
}
