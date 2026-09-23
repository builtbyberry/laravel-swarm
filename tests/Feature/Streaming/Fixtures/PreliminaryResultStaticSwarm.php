<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::StaticHierarchical)]
class PreliminaryResultStaticSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function __construct(private string $scenario = 'ordinary', private string $agentClass = PreliminaryResultAgent::class) {}

    public function agents(): array
    {
        return [new $this->agentClass];
    }

    public function plan(): array
    {
        return ['start_at' => 'worker', 'nodes' => [
            'worker' => ['type' => 'worker', 'agent' => $this->agentClass, 'prompt' => $this->scenario, 'next' => 'finish'],
            'finish' => ['type' => 'finish', 'output_from' => 'worker'],
        ]];
    }
}
