<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\ConversationAgent;

#[Topology(TopologyEnum::Sequential)]
final class ConversationSequentialSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new ConversationAgent];
    }
}
