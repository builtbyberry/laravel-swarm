<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\MemoryReplayCoordinator;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableHierarchicalStepResult;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;
use BuiltByBerry\LaravelSwarm\Support\BranchWaitPayload;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;

/**
 * @internal
 */
class DurableHierarchicalCoordinator
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected Application $application,
        protected DurableRunContext $runs,
        protected DurableBranchCoordinator $branches,
        protected HierarchicalRunner $hierarchical,
        protected DurableOutbox $outbox,
        protected MemoryReplayCoordinator $coordinator,
    ) {}

    /**
     * @param  array<string, mixed>  $run
     */
    public function runStep(SwarmExecutionState $state, array $run, string $token, RunContext $context, int $stepLeaseSeconds, int $expectedStepIndex, callable $failCurrentRun): ?DurableHierarchicalStepResult
    {
        if (is_string($run['current_node_id'] ?? null) && $this->branchJoinShouldFail($run, $context, $run['current_node_id'])) {
            $failCurrentRun($run, $token, $context, $stepLeaseSeconds, $run['current_node_id']);

            return null;
        }

        /** @var DurableHierarchicalStepResult */
        return $this->coordinator->during(
            $state->swarm::class,
            $state->context->runId,
            $expectedStepIndex,
            fn (?MemorySnapshot $existing) => $this->hierarchical->runDurableStep($state, $expectedStepIndex, $run),
        );
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function checkpointBranchWait(array $run, string $token, int $nextStepIndex, RunContext $context, int $stepLeaseSeconds, DurableHierarchicalStepResult $result, ?callable $withTransaction = null): void
    {
        $swarm = $this->application->make($run['swarm_class']);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve durable swarm [{$run['swarm_class']}] from the container.");
        }

        $branches = array_map(fn (array $branch): array => $this->branches->withBranchRouting($swarm, $context, $branch, $run), $result->branches);

        $this->connection->transaction(function () use ($run, $token, $nextStepIndex, $context, $stepLeaseSeconds, $result, $branches, $withTransaction): void {
            $this->historyStore->syncDurableState($run['run_id'], 'running', $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false, $token, $stepLeaseSeconds);
            $this->durableRuns->waitForBranches($run['run_id'], new BranchWaitPayload(
                executionToken: $token,
                nextStepIndex: $nextStepIndex,
                parentNodeId: (string) $result->waitingParentNodeId,
                context: $this->capture->activeContext($context),
                ttlSeconds: $this->runs->ttlSeconds(),
                routeCursor: $result->routeCursor,
                routePlan: $result->routePlan,
                totalSteps: $result->totalSteps,
                branches: $branches,
            ));
            if ($withTransaction !== null) {
                ($withTransaction)();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function dispatchWaitingBoundary(array $run, bool $dispatchRecoverableBranches): void
    {
        if ($run['status'] !== 'waiting') {
            return;
        }

        $parentNodeId = $run['current_node_id'];

        if (! is_string($parentNodeId)) {
            return;
        }

        $branches = $this->durableRuns->branchesFor($run['run_id'], $parentNodeId);

        if ($this->branches->branchesAreTerminal($branches)) {
            $this->connection->transaction(function () use ($run): void {
                if (! $this->durableRuns->releaseWaitingRunForJoin($run['run_id'], (int) $run['next_step_index'])) {
                    return;
                }

                $updated = $this->runs->requireRun($run['run_id']);

                if (($run['coordination_profile'] ?? CoordinationProfile::StepDurable->value) === CoordinationProfile::QueueHierarchicalParallel->value) {
                    $this->outbox->enqueueQueuedResume($run['run_id'], $updated['queue_connection'], $updated['queue_name']);
                } else {
                    $this->outbox->enqueueStep($run['run_id'], (int) $updated['next_step_index'], $updated['queue_connection'], $updated['queue_name']);
                }
            });

            return;
        }

        if (! $dispatchRecoverableBranches) {
            return;
        }

        foreach ($branches as $branch) {
            if (! $this->branches->branchShouldBeRedispatched($branch)) {
                continue;
            }

            $this->outbox->enqueueBranch(
                $run['run_id'],
                (string) $branch['branch_id'],
                $branch['queue_connection'] ?? $run['queue_connection'],
                $branch['queue_name'] ?? $run['queue_name'],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function failWaitingParentFromBranches(array $run, RunContext $context, int $stepLeaseSeconds, callable $failCurrentRun): void
    {
        if ($run['status'] !== 'waiting') {
            return;
        }

        $parentNodeId = is_string($run['current_node_id'] ?? null) ? $run['current_node_id'] : null;
        $branches = $this->durableRuns->branchesFor($run['run_id'], $parentNodeId);
        $failed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'failed'));

        if ($failed === []) {
            return;
        }

        if (! $this->durableRuns->releaseWaitingRunForJoin($run['run_id'], (int) $run['next_step_index'])) {
            return;
        }

        $fresh = $this->runs->requireRun($run['run_id']);
        $token = $this->durableRuns->acquireLease($run['run_id'], (int) $fresh['next_step_index'], $stepLeaseSeconds);

        if ($token === null) {
            return;
        }

        $failCurrentRun($fresh, $token, $context, $stepLeaseSeconds, $parentNodeId);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function branchJoinShouldFail(array $run, RunContext $context, string $parentNodeId): bool
    {
        $branches = $this->durableRuns->branchesFor($run['run_id'], $parentNodeId);

        if (! $this->branches->branchesAreTerminal($branches)) {
            return false;
        }

        $failed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'failed'));

        if ($failed === []) {
            return false;
        }

        $completed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'completed'));
        $policy = $this->branches->parallelFailurePolicy($context);

        return $policy !== DurableParallelFailurePolicy::PartialSuccess || $completed === [];
    }
}
