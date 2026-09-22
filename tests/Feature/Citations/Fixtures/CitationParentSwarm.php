<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ChildDispatchingSwarm;

#[Topology(TopologyEnum::Sequential)]
class CitationParentSwarm extends ChildDispatchingSwarm
{
    public function agents(): array
    {
        return [new CitingAgent, new OtherCitingAgent];
    }

    public function durableChildSwarms(RunContext $context): array
    {
        return [['swarm' => CitationStaticSwarm::class, 'task' => 'child-task']];
    }
}
