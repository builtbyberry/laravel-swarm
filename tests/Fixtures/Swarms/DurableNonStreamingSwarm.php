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
 * The same three-node sequential durable swarm as {@see DurableStreamingSwarm} but
 * WITHOUT `#[DurableStreaming]`, so it never opts into durable per-node streaming.
 * Used to prove an un-attributed swarm writes nothing to the causal log (#310).
 */
#[Topology(TopologyEnum::Sequential)]
#[DurableRetry(maxAttempts: 2, backoffSeconds: [60])]
class DurableNonStreamingSwarm implements Swarm
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
