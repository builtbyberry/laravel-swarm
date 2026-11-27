<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StructuredOutputWorker;

/**
 * A parallel swarm with one structured-output branch, for the structured
 * response text fallback on the parallel and durable branch paths.
 */
#[Topology(TopologyEnum::Parallel)]
class StructuredOutputParallelSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new StructuredOutputWorker,
            new FakeWriter,
        ];
    }
}
