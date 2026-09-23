<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\ProviderTools\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;

#[Topology(TopologyEnum::Hierarchical)]
class ProviderGeneratedSwarm extends ProviderSwarm
{
    public function agents(): array
    {
        return [new FakeHierarchicalCoordinator, new ProviderAgent];
    }
}
