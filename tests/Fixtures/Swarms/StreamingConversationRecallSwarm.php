<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StreamingConversationRecallAgent;

/**
 * Single-step sequential swarm whose only agent recalls Conversation-scoped
 * memory mid-stream. Used (with a Conversation-declaring propagation policy) to
 * assert the conversation handle stays frame-isolated across two interleaved
 * in-process streamed runs (#186 × the streaming run frame).
 */
#[Topology(TopologyEnum::Sequential)]
class StreamingConversationRecallSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new StreamingConversationRecallAgent,
        ];
    }
}
