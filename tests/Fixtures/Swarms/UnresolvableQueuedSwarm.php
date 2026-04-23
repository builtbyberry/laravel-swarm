<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ConfigurableOutputAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support\UnboundQueuedDependency;

#[Topology(TopologyEnum::Sequential)]
class UnresolvableQueuedSwarm implements Swarm
{
    use Runnable;

    public function __construct(
        protected UnboundQueuedDependency $dependency,
    ) {}

    public function agents(): array
    {
        return [
            new ConfigurableOutputAgent('unreachable'),
        ];
    }
}
