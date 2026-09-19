<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::StaticHierarchical)]
class NativeResultStaticSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function __construct(private string $label = 'ordinary') {}

    public function agents(): array
    {
        return [new NativeResultAgent];
    }

    public function plan(): array
    {
        return ['start_at' => 'worker', 'nodes' => [
            'worker' => ['type' => 'worker', 'agent' => NativeResultAgent::class, 'prompt' => $this->label, 'next' => 'finish'],
            'finish' => ['type' => 'finish', 'output_from' => 'worker'],
        ]];
    }
}
