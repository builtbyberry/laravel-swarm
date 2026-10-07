<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryToolAgent;

/**
 * Sequential swarm around {@see MemoryToolAgent}, which uses the memory tools
 * trait without asking for the agent scope.
 */
final class MemoryToolSequentialSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new MemoryToolAgent];
    }
}
