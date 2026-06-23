<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\UnknownThenErrorStreamEditor;

/**
 * Sequential swarm whose worker streams an unrecognized event and then a
 * provider error — the breadcrumb's finally runs while a
 * SwarmStreamProviderException is unwinding.
 */
#[Topology(TopologyEnum::Sequential)]
class FakeUnknownThenErrorStreamingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new UnknownThenErrorStreamEditor,
        ];
    }
}
