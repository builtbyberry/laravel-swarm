<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Execution;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;

#[Execution(ExecutionMode::Queued)]
class SwarmWithExecutionAttribute implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [];
    }
}
