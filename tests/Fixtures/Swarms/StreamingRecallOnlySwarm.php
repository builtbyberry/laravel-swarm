<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StreamingRecallAgent;

/**
 * Single-step sequential swarm whose ONLY agent is the streaming recall agent —
 * so index 0 IS the streamed (terminal) step. With no primer to re-write run
 * memory on resume, the crash-replay shield test is non-vacuous: the only thing
 * that can surface the recalled value is the frozen snapshot itself, never a
 * re-executed earlier step.
 */
#[Topology(TopologyEnum::Sequential)]
class StreamingRecallOnlySwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new StreamingRecallAgent,
        ];
    }
}
