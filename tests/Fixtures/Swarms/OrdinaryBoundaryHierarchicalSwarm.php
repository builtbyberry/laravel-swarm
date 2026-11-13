<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableWait;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Hierarchical)]
#[DurableWait('approval_received', timeout: 3600)]
class OrdinaryBoundaryHierarchicalSwarm extends FakeHierarchicalFullSwarm {}
