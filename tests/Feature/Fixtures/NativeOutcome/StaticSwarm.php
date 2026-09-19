<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

#[Topology(TopologyEnum::StaticHierarchical)]
class StaticSwarm extends SequentialSwarm implements HasRoutePlan
{
    public function agents(): array
    {
        return config('tests.native.mixed')
            ? [new FailingAgent, new PendingAgent, new ThrowingApprovalAgent]
            : parent::agents();
    }

    public function plan(): array
    {
        return config('tests.native.plan', [
            'start_at' => 'pending',
            'nodes' => [
                'pending' => ['type' => 'worker', 'agent' => PendingAgent::class, 'prompt' => 'task', 'next' => 'writer'],
                'writer' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'task'],
            ],
        ]);
    }
}
