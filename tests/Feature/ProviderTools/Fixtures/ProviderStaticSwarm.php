<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\ProviderTools\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::StaticHierarchical)]
class ProviderStaticSwarm extends ProviderSwarm implements HasRoutePlan
{
    public function plan(): array
    {
        return ['start_at' => 'first', 'nodes' => [
            'first' => ['type' => 'worker', 'agent' => ProviderAgent::class, 'prompt' => 'first', 'next' => 'second'],
            'second' => ['type' => 'worker', 'agent' => ProviderAgent::class, 'prompt' => 'second', 'next' => 'finish'],
            'finish' => ['type' => 'finish', 'output_from' => 'second'],
        ]];
    }
}
