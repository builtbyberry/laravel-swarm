<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingPrimerAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StreamingRecallAgent;

/**
 * Sequential swarm: a non-streaming primer step writes a value to run memory,
 * then the streaming final agent reads it back via the real Recall tool
 * mid-stream — proving a streamed recall sees an earlier step's write.
 */
#[Topology(TopologyEnum::Sequential)]
class StreamingRecallSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new RememberingPrimerAgent,
            new StreamingRecallAgent,
        ];
    }
}
