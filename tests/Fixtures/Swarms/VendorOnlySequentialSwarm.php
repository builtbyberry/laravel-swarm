<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyWriter;

/**
 * A class-based sequential swarm of plain `laravel/ai` agents.
 *
 * Backs the durable and streaming coverage: both modes re-resolve the swarm
 * from the container by class, so they need a declared class rather than the
 * inline builders the other vendor-only tests use.
 */
#[Topology(TopologyEnum::Sequential)]
class VendorOnlySequentialSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new VendorOnlyResearcher, new VendorOnlyWriter];
    }
}
