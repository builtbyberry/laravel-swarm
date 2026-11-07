<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\BatchingStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;

/**
 * A three-step sequential durable streaming swarm whose first step is scripted by
 * {@see BatchingStreamEditor}. Only the last step's checkpoint is terminal, so
 * steps 0 and 1 each write a seal barrier.
 */
#[Topology(TopologyEnum::Sequential)]
#[DurableRetry(maxAttempts: 2, backoffSeconds: [60])]
#[DurableStreaming]
class DurableBatchingStreamingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new BatchingStreamEditor('batch'),
            new PlainStreamEditor('after'),
            new PlainStreamEditor('last'),
        ];
    }
}
