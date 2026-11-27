<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ToolInputStreamEditor;

/** Two parallel branches that both stream tool input with the same native event ids. */
#[Topology(TopologyEnum::Parallel)]
final class ParallelToolInputStreamSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new ToolInputStreamEditor, new ToolInputStreamEditor];
    }
}
