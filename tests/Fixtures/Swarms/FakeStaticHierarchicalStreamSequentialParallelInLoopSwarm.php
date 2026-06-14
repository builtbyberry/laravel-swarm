<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps;
use BuiltByBerry\LaravelSwarm\Attributes\StreamParallelBranches;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

/**
 * The parallel-in-loop plan, streamed with `sequential` parallel-branch mode so
 * the loop-iteration stamping on the sequential branch path is exercised.
 *
 * Inherits plan() and agents() from the concurrent fixture; PHP attributes are
 * not inherited, so #[Topology], #[StreamParallelBranches] and #[MaxAgentSteps]
 * are re-declared here.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
#[StreamParallelBranches('sequential')]
#[MaxAgentSteps(20)]
class FakeStaticHierarchicalStreamSequentialParallelInLoopSwarm extends FakeStaticHierarchicalParallelInLoopSwarm {}
