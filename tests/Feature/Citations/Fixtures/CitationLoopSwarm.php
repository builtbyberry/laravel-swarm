<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::StaticHierarchical)]
#[MaxAgentSteps(6)]
class CitationLoopSwarm extends CitationStaticSwarm
{
    public function plan(): array
    {
        $plan = parent::plan();
        $plan['nodes']['second']['loop'] = ['to' => 'second', 'max_iterations' => 3];
        $plan['nodes']['finish']['output_from'] = 'second';

        return $plan;
    }
}
