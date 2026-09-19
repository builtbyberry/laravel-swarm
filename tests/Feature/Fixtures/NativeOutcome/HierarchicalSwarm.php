<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;

#[Topology(TopologyEnum::Hierarchical)]
class HierarchicalSwarm extends SequentialSwarm
{
    public function agents(): array
    {
        return [app(FakeHierarchicalCoordinator::class), ...parent::agents()];
    }
}
