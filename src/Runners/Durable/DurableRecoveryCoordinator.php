<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaitTimedOut;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;

class DurableRecoveryCoordinator
{
    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected Dispatcher $events,
        protected SwarmCapture $capture,
        protected DurableRunContext $runs,
        protected DurableJobDispatcher $jobs,
        protected DurableChildSwarmCoordinator $children,
    ) {}

    /**
     * @return array<int, string>
     */
    public function recover(?string $runId = null, ?string $swarmClass = null, int $limit = 50, ?callable $dispatchStep = null, ?callable $dispatchBranch = null): array
    {
        $dispatchStep ??= fn (string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null) => $this->jobs->dispatchStep($runId, $stepIndex, $connection, $queue);
        $dispatchBranch ??= fn (string $runId, string $branchId, ?string $connection = null, ?string $queue = null) => $this->jobs->dispatchBranch($runId, $branchId, $connection, $queue);

        $runs = $this->durableRuns->recoverable(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
            graceSeconds: (int) $this->config->get('swarm.durable.recovery.grace_seconds', 300),
        );

        foreach ($runs as $run) {
            $dispatch = $dispatchStep($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
            unset($dispatch);

            $this->durableRuns->markRecoveryDispatched($run['run_id']);
        }

        $branches = $this->durableRuns->recoverableBranches(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
            graceSeconds: (int) $this->config->get('swarm.durable.recovery.grace_seconds', 300),
        );

        foreach ($branches as $branch) {
            $dispatch = $dispatchBranch($branch['run_id'], $branch['branch_id'], $branch['queue_connection'], $branch['queue_name']);
            unset($dispatch);
            $this->durableRuns->markBranchRecoveryDispatched($branch['run_id'], $branch['branch_id']);
        }

        $dueRetryRuns = $this->durableRuns->dueRetries(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($dueRetryRuns as $run) {
            $dispatch = $dispatchStep($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
            unset($dispatch);
            $this->durableRuns->markRetryRecoveryDispatched($run['run_id']);
        }

        $dueRetryBranches = $this->durableRuns->dueRetryBranches(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($dueRetryBranches as $branch) {
            $dispatch = $dispatchBranch($branch['run_id'], $branch['branch_id'], $branch['queue_connection'], $branch['queue_name']);
            unset($dispatch);
            $this->durableRuns->markBranchRetryRecoveryDispatched($branch['run_id'], $branch['branch_id']);
        }

        $waitingJoins = $this->durableRuns->recoverableWaitingJoins(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
            graceSeconds: (int) $this->config->get('swarm.durable.recovery.grace_seconds', 300),
        );

        foreach ($waitingJoins as $run) {
            if ($this->durableRuns->releaseWaitingRunForJoin($run['run_id'], (int) $run['next_step_index'])) {
                if (($run['coordination_profile'] ?? CoordinationProfile::StepDurable->value) === CoordinationProfile::QueueHierarchicalParallel->value) {
                    $this->jobs->dispatchQueuedHierarchicalResume($run);
                } else {
                    $dispatch = $dispatchStep($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
                    unset($dispatch);
                }

                $this->durableRuns->markRecoveryDispatched($run['run_id']);
            }
        }

        $timedOutWaits = $this->durableRuns->recoverableTimedOutWaits(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($timedOutWaits as $wait) {
            $waitName = (string) $wait['wait_name'];

            if ($this->durableRuns->releaseTimedOutWait($wait['run_id'], $waitName)) {
                $updated = $this->runs->requireRun($wait['run_id']);
                $context = $this->runs->loadContext($wait['run_id']);
                $outcomes = is_array($context->metadata['durable_wait_outcomes'] ?? null) ? $context->metadata['durable_wait_outcomes'] : [];
                $outcomes[$waitName] = ['status' => 'timed_out', 'payload' => null, 'timed_out' => true];
                $context->mergeMetadata(['durable_wait_outcomes' => $outcomes]);
                $this->contextStore->put($this->capture->activeContext($context), $this->runs->ttlSeconds());
                $this->historyStore->syncDurableState($wait['run_id'], $updated['status'], $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false);

                $this->events->dispatch(new SwarmWaitTimedOut(
                    runId: $wait['run_id'],
                    swarmClass: $wait['swarm_class'],
                    topology: $wait['topology'],
                    waitName: $waitName,
                    executionMode: $this->runs->publicLifecycleExecutionMode($wait),
                ));

                if ($updated['status'] === 'pending') {
                    $dispatch = $dispatchStep($wait['run_id'], (int) $updated['next_step_index'], $updated['queue_connection'], $updated['queue_name']);
                    unset($dispatch);
                    $this->durableRuns->markRecoveryDispatched($wait['run_id']);
                }
            }
        }

        $childParents = $this->durableRuns->parentsWaitingOnTerminalChildren(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($childParents as $run) {
            $this->children->reconcileTerminalChildrenForParent($run, $dispatchStep);
        }

        $undispatchedChildren = $this->durableRuns->undispatchedChildRuns(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($undispatchedChildren as $child) {
            $this->children->dispatchChildIntent($child, $dispatchStep);
        }

        return array_values(array_unique(array_merge(
            array_map(static fn (array $run): string => $run['run_id'], $runs),
            array_map(static fn (array $branch): string => $branch['run_id'], $branches),
            array_map(static fn (array $run): string => $run['run_id'], $dueRetryRuns),
            array_map(static fn (array $branch): string => $branch['run_id'], $dueRetryBranches),
            array_map(static fn (array $run): string => $run['run_id'], $waitingJoins),
            array_map(static fn (array $run): string => $run['run_id'], $timedOutWaits),
            array_map(static fn (array $run): string => $run['run_id'], $childParents),
            array_map(static fn (array $child): string => $child['parent_run_id'], $undispatchedChildren),
        )));
    }
}
