<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StreamingRememberAgent;

/**
 * Single-step sequential swarm whose streaming final agent writes to run memory
 * via the real Remember tool mid-stream.
 */
#[Topology(TopologyEnum::Sequential)]
class StreamingRememberSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new StreamingRememberAgent,
        ];
    }
}
