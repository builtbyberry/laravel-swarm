<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StreamingUnpairedToolCallAgent;

/**
 * Single-step sequential swarm whose streaming final agent emits a tool call
 * with no matching tool result, so the runner records the call in the step
 * snapshot with a null result.
 */
#[Topology(TopologyEnum::Sequential)]
class StreamingUnpairedToolCallSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new StreamingUnpairedToolCallAgent,
        ];
    }
}
