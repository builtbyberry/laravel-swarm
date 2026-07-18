<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

/**
 * A class-free sequential swarm built at call time by
 * {@see \BuiltByBerry\LaravelSwarm\Runners\SwarmRunner::sequential()}.
 *
 * The topology is pinned with the standard `#[Topology]` attribute so it flows
 * through the normal attribute-resolution path — no bespoke runner, and no
 * reliance on the `swarm.topology` config default (which could be anything).
 *
 * @internal Constructed by SwarmRunner / PendingSwarmRun, not by consumers.
 */
#[Topology(TopologyEnum::Sequential)]
class AdHocSequentialSwarm extends AdHocSwarm {}
