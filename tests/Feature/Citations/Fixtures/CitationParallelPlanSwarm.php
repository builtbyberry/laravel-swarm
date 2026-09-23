<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::StaticHierarchical)]
class CitationParallelPlanSwarm extends CitationStaticSwarm
{
    public function plan(): array
    {
        return ['start_at' => 'parallel', 'nodes' => [
            'parallel' => ['type' => 'parallel', 'branches' => ['first', 'second'], 'next' => 'finish'],
            'first' => ['type' => 'worker', 'agent' => CitingAgent::class, 'prompt' => 'first'],
            'second' => ['type' => 'worker', 'agent' => OtherCitingAgent::class, 'prompt' => 'second'],
            'finish' => ['type' => 'finish', 'output_from' => 'first'],
        ]];
    }
}
