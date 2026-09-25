<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Tools\NativeSettingsTool;
use Laravel\Ai\Messages\UserMessage;

#[Topology(TopologyEnum::Parallel)]
final class AuthoredNativeSettingsParallelSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            (new FakeResearcher)
                ->withTools([new NativeSettingsTool('authored')])
                ->withMessages([new UserMessage('authored history')]),
            new FakeWriter,
        ];
    }
}
