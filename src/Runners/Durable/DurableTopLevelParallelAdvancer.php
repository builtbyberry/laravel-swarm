<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\BranchWaitPayload;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Database\Connection;

class DurableTopLevelParallelAdvancer
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected DurableRunContext $runs,
        protected DurableBranchCoordinator $branches,
        protected DurableRunTerminalHandler $terminal,
    ) {}

    /**
     * @param  array<string, mixed>  $run
     */
    public function advance(SwarmExecutionState $state, array $run, string $token, int $stepLeaseSeconds, int $expectedStepIndex, callable $dispatchBranch, callable $dispatchStep): void
    {
        if ($expectedStepIndex === 0) {
            $this->startBranches($state, $run, $token, $stepLeaseSeconds, $dispatchBranch);

            return;
        }

        $this->joinBranches($state, $run, $token, $stepLeaseSeconds, $dispatchStep);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function startBranches(SwarmExecutionState $state, array $run, string $token, int $stepLeaseSeconds, callable $dispatchBranch): void
    {
        $branches = [];
        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);
        $input = $state->context->prompt();

        foreach ($agents as $index => $agent) {
            $branch = [
                'branch_id' => 'parallel:'.$index,
                'step_index' => $index,
                'node_id' => null,
                'agent_class' => $agent::class,
                'parent_node_id' => 'parallel',
                'input' => $input,
                'metadata' => ['parallel_branch_index' => $index],
            ];
            $branches[] = $this->branches->withBranchRouting($state->swarm, $state->context, $branch, $run);
        }

        $this->connection->transaction(function () use ($token, $state, $stepLeaseSeconds, $branches): void {
            $this->historyStore->syncDurableState($state->context->runId, 'running', $this->capture->context($state->context), $state->context->metadata, $this->runs->ttlSeconds(), false, $token, $stepLeaseSeconds);
            $this->durableRuns->waitForBranches($state->context->runId, new BranchWaitPayload(
                executionToken: $token,
                nextStepIndex: count($branches),
                parentNodeId: 'parallel',
                context: $this->capture->activeContext($state->context),
                ttlSeconds: $this->runs->ttlSeconds(),
                branches: $branches,
            ));
        });

        foreach ($branches as $branch) {
            $dispatch = $dispatchBranch($state->context->runId, (string) $branch['branch_id'], $branch['queue_connection'] ?? $run['queue_connection'], $branch['queue_name'] ?? $run['queue_name']);
            unset($dispatch);
        }
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function joinBranches(SwarmExecutionState $state, array $run, string $token, int $stepLeaseSeconds, callable $dispatchStep): void
    {
        $branches = $this->durableRuns->branchesFor($state->context->runId, 'parallel');

        if (! $this->branches->branchesAreTerminal($branches)) {
            $this->durableRuns->waitForBranches($state->context->runId, new BranchWaitPayload(
                executionToken: $token,
                nextStepIndex: (int) $run['next_step_index'],
                parentNodeId: 'parallel',
                context: $this->capture->activeContext($state->context),
                ttlSeconds: $this->runs->ttlSeconds(),
            ));

            return;
        }

        $completed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'completed'));
        $failed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'failed'));
        $policy = $this->branches->parallelFailurePolicy($state->context);

        if ($failed !== [] && ($policy !== DurableParallelFailurePolicy::PartialSuccess || $completed === [])) {
            $this->terminal->failCurrentRunFromBranchFailures($run, $token, $state->context, $stepLeaseSeconds, 'parallel', $dispatchStep);

            return;
        }

        usort($completed, static fn (array $a, array $b): int => ((int) $a['step_index']) <=> ((int) $b['step_index']));

        $outputs = array_map(static fn (array $branch): string => (string) $branch['output'], $completed);
        $usage = $this->branches->mergeBranchUsage($completed);
        $output = implode("\n\n", $outputs);
        $state->context
            ->mergeData([
                'last_output' => $output,
                'steps' => count($completed),
            ])
            ->mergeMetadata([
                'topology' => $state->topology->value,
                'usage' => $usage,
                'durable_parallel_branches' => $this->branches->branchSummaries($branches),
                'executed_agent_classes' => array_values(array_map(static fn (array $branch): string => (string) $branch['agent_class'], $completed)),
            ]);

        $this->terminal->completeRun(array_merge($run, ['run_id' => $state->context->runId]), $token, $state->context, $stepLeaseSeconds, null, $dispatchStep);
    }
}
