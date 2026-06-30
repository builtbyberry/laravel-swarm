<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;

/**
 * A hierarchical `#[DurableStreaming]` swarm whose coordinator (step 0) crashes
 * before its checkpoint on the first attempt (#311 coordinator-crash-and-resume).
 *
 * The coordinator opens the reserved `__coordinator__` node bracket and then throws,
 * leaving that node_opened orphaned above the checkpoint. The retry attribute lets
 * step 0 re-dispatch; on resume voidPriorAttempt('__coordinator__', …) retracts the
 * crashed open before the fresh attempt re-plans, so the clean fold shows exactly one
 * coordinator attempt. The single clean worker keeps the run focused on the
 * coordinator window under test.
 */
#[Topology(TopologyEnum::Hierarchical)]
#[DurableRetry(maxAttempts: 2, backoffSeconds: [60])]
#[DurableStreaming]
class FlakyCoordinatorDurableStreamingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FlakyHierarchicalCoordinator,
            new PlainStreamEditor('writer'),
        ];
    }
}
