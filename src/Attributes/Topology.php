<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Attribute(Attribute::TARGET_CLASS)]
final class Topology
{
    /**
     * @param  TopologyEnum  $topology  Default execution topology for this swarm.
     */
    public function __construct(public TopologyEnum $topology) {}
}
