<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableWaits;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

#[Topology(TopologyEnum::Hierarchical)]
class JoinBoundaryWaitSwarm extends FakeHierarchicalFullSwarm implements RoutesDurableWaits
{
    public function durableWaits(RunContext $context): array
    {
        return ($context->metadata['completed_steps'] ?? 0) >= 3
            ? [['name' => 'declared-approval'], ['name' => 'later-approval']]
            : [];
    }
}
