<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StructuredOutputWorker;

/**
 * A `#[DurableStreaming]` sequential swarm whose worker declares structured output —
 * the dispatch-time guard target for #321. Dispatch must fail loud before any job
 * runs, not mid-execution when the worker's stream() site is first reached.
 */
#[Topology(TopologyEnum::Sequential)]
#[DurableStreaming]
class DurableSequentialStructuredWorkerStreamingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new StructuredOutputWorker,
        ];
    }
}
