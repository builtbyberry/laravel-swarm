<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Streaming\StreamEventMapper;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;

/**
 * A single clean streaming node that opts into durable per-node streaming. Used to
 * prove the live {@see SequentialRunner::stream()}
 * path and the durable per-node stream emit an identical body event sequence through
 * the shared {@see StreamEventMapper} (#310 F2).
 */
#[Topology(TopologyEnum::Sequential)]
#[DurableStreaming]
class SoloDurableStreamingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new PlainStreamEditor('solo'),
        ];
    }
}
