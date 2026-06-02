<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\PropagationPolicy;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Memory\ConversationPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingWriter;

/**
 * A two-step sequential swarm that opts into ConversationPropagationPolicy. The
 * researcher runs first (its output is captured under swarm:step.0.output); the
 * RememberingWriter runs second and should see that prior step's output rendered
 * as a turn — the turn-by-turn conversation #163 exists to enable.
 */
#[Topology(TopologyEnum::Sequential)]
#[PropagationPolicy(ConversationPropagationPolicy::class)]
class RememberingConversationSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeResearcher,
            new RememberingWriter,
        ];
    }
}
