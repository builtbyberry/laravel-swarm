<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\ConversationAgent;

#[Topology(TopologyEnum::StaticHierarchical)]
class ConversationStaticHierarchicalSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new ConversationAgent];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'conversation',
            'nodes' => [
                'conversation' => [
                    'type' => 'worker',
                    'agent' => ConversationAgent::class,
                    'prompt' => 'continue',
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'conversation',
                ],
            ],
        ];
    }
}
