<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::StaticHierarchical)]
class CitationStaticSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new CitingAgent, new OtherCitingAgent];
    }

    public function plan(): array
    {
        return ['start_at' => 'first', 'nodes' => [
            'first' => ['type' => 'worker', 'agent' => CitingAgent::class, 'prompt' => 'first', 'next' => 'second'],
            'second' => ['type' => 'worker', 'agent' => OtherCitingAgent::class, 'prompt' => 'rewrite', 'next' => 'finish'],
            'finish' => ['type' => 'finish', 'output_from' => 'first'],
        ]];
    }
}
