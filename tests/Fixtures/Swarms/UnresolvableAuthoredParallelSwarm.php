<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;

#[Topology(TopologyEnum::Parallel)]
final class UnresolvableAuthoredParallelSwarm implements Swarm
{
    use Runnable;

    public function __construct(string $runtimeState)
    {
        if ($runtimeState === '') {
            throw new \InvalidArgumentException('Runtime state must be non-empty.');
        }
    }

    public function agents(): array
    {
        return [new FakeResearcher];
    }
}
