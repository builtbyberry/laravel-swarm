<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ConfigurableOutputAgent;

#[Topology(TopologyEnum::Sequential)]
class ContainerResolvedQueuedSwarm implements Swarm
{
    use Runnable;

    public function __construct(
        protected string $output,
    ) {}

    public function agents(): array
    {
        return [
            new ConfigurableOutputAgent($this->output),
        ];
    }
}
