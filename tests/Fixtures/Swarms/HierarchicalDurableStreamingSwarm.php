<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

/**
 * A hierarchical swarm that opts into `#[DurableStreaming]`. Hierarchical durable
 * streaming is not wired until #311, so dispatching this durably must FAIL LOUD at
 * the topology guard rather than silently pinning the opt-in and never streaming
 * (#310 forcing function). Delete this fixture's negative test when #311 lands.
 */
#[Topology(TopologyEnum::Hierarchical)]
#[DurableStreaming]
class HierarchicalDurableStreamingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeHierarchicalCoordinator,
            new FakeWriter,
            new FakeEditor,
            new FakeResearcher,
        ];
    }
}
