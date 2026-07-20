<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Support\PendingRun;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyWriter;

/**
 * A class-based sequential swarm of plain `laravel/ai` agents.
 *
 * Backs the durable and streaming coverage. Only **durable** actually requires
 * a declared class: a background run is re-resolved from the container by class
 * on a later worker, which an ad-hoc swarm cannot provide, so
 * {@see PendingRun} deliberately omits
 * `dispatchDurable()`. Streaming works fine on the inline builders
 * (`PendingRun::stream()` exists) — this fixture is reused there for symmetry,
 * not out of necessity.
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
