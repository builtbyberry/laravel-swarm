<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;

/**
 * A three-node sequential durable swarm whose middle node crashes mid-stream on
 * its first attempt — the #298 per-node streaming resume scenario. The retry
 * attribute lets the crashed node re-execute after backoff.
 */
#[Topology(TopologyEnum::Sequential)]
#[DurableRetry(maxAttempts: 2, backoffSeconds: [60])]
class DurableStreamingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new PlainStreamEditor('first'),
            new FlakyStreamEditor,
            new PlainStreamEditor('third'),
        ];
    }
}
