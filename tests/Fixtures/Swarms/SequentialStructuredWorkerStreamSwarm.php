<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StructuredOutputWorker;

/**
 * A sequential swarm whose single (and therefore final, streamed) agent declares
 * structured output — the live `stream()` guard target for #321.
 */
#[Topology(TopologyEnum::Sequential)]
class SequentialStructuredWorkerStreamSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new StructuredOutputWorker,
        ];
    }
}
