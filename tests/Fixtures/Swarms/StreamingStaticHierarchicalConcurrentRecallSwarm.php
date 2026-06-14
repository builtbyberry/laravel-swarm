<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\StreamParallelBranches;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PromptRecallAgentA;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PromptRecallAgentB;

/**
 * Concurrent (ConcurrencyManager-dispatched) parallel group of two recall
 * branch workers. Each branch is its own step index with its own frozen
 * snapshot, so a crash-resume proves each branch reconstructs and reads its OWN
 * frozen view (OG1b, concurrent-branch resume). Forced concurrent via the
 * attribute; the test forces the Sync concurrency driver so the fork/process
 * reconstruction path is exercised in a CI-verifiable way.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
#[StreamParallelBranches('concurrent')]
class StreamingStaticHierarchicalConcurrentRecallSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new PromptRecallAgentA,
            new PromptRecallAgentB,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'parallel_recall',
            'nodes' => [
                'parallel_recall' => [
                    'type' => 'parallel',
                    'branches' => ['branch_a', 'branch_b'],
                    'next' => 'finish',
                ],
                'branch_a' => [
                    'type' => 'worker',
                    'agent' => PromptRecallAgentA::class,
                    'prompt' => 'recall-task-a',
                ],
                'branch_b' => [
                    'type' => 'worker',
                    'agent' => PromptRecallAgentB::class,
                    'prompt' => 'recall-task-b',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'branch_a',
                ],
            ],
        ];
    }
}
