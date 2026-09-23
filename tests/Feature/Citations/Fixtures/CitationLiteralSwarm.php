<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::StaticHierarchical)]
class CitationLiteralSwarm extends CitationStaticSwarm
{
    public function plan(): array
    {
        $plan = parent::plan();
        $plan['nodes']['finish'] = ['type' => 'finish', 'output' => 'literal answer'];

        return $plan;
    }
}
