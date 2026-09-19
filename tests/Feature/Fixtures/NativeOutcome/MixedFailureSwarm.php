<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Parallel)]
class MixedFailureSwarm extends ParallelSwarm
{
    public function agents(): array
    {
        return [new FailingAgent, config('tests.native.throw') ? new ThrowingApprovalAgent : new PendingAgent];
    }
}
