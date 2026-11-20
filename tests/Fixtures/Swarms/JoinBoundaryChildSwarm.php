<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Contracts\DispatchesChildSwarms;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

#[Topology(TopologyEnum::Hierarchical)]
class JoinBoundaryChildSwarm extends FakeHierarchicalFullSwarm implements DispatchesChildSwarms
{
    public function durableChildSwarms(RunContext $context): array
    {
        return ($context->metadata['completed_steps'] ?? 0) >= 3
            ? [['swarm' => FakeSequentialSwarm::class, 'task' => 'operational-child-input']]
            : [];
    }
}
