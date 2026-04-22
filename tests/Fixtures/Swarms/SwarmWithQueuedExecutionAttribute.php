<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Execution;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;

#[Execution(ExecutionMode::Queued)]
class SwarmWithQueuedExecutionAttribute implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeResearcher,
        ];
    }
}
