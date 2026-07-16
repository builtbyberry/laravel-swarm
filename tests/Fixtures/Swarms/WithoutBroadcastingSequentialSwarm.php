<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use Laravel\Ai\Attributes\WithoutBroadcasting;

/**
 * A sequential swarm that suppresses the high-frequency {@see SwarmTextDelta}
 * stream events from broadcast via laravel/ai's #[WithoutBroadcasting] attribute,
 * while leaving every other event type (and the underlying stream / persistence)
 * untouched.
 */
#[Topology(TopologyEnum::Sequential)]
#[WithoutBroadcasting(SwarmTextDelta::class)]
class WithoutBroadcastingSequentialSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeResearcher,
            new FakeWriter,
            new FakeEditor,
        ];
    }
}
