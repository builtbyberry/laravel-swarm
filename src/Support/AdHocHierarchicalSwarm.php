<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

/**
 * A class-free hierarchical swarm built at call time by
 * {@see \BuiltByBerry\LaravelSwarm\Runners\SwarmRunner::hierarchical()}.
 *
 * `agents()[0]` is the coordinator and the remaining entries are its workers,
 * matching the hierarchical contract enforced by the runner. The topology is
 * pinned with the standard `#[Topology]` attribute so it flows through the
 * normal attribute-resolution path — no bespoke runner.
 *
 * @internal Constructed by SwarmRunner / PendingSwarmRun, not by consumers.
 */
#[Topology(TopologyEnum::Hierarchical)]
class AdHocHierarchicalSwarm extends AdHocSwarm {}
