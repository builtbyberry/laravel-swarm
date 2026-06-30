<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;

/**
 * A hierarchical swarm that opts into `#[DurableStreaming]` (#311). The coordinator
 * (step 0) runs via prompt() and ships STRUCTURAL-ONLY — it brackets the run with
 * node-structure events under the reserved `__coordinator__` node id (token
 * streaming is a decoupled #314 fast-follow). The two worker nodes stream per-node
 * into the causal log under their plan node ids; the flaky worker crashes mid-node
 * on its first attempt so the resume void/seal is exercised. The retry attribute
 * lets the crashed node re-execute after backoff.
 */
#[Topology(TopologyEnum::Hierarchical)]
#[DurableRetry(maxAttempts: 2, backoffSeconds: [60])]
#[DurableStreaming]
class HierarchicalDurableStreamingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeHierarchicalCoordinator,
            new PlainStreamEditor('writer'),
            new FlakyStreamEditor,
        ];
    }
}
