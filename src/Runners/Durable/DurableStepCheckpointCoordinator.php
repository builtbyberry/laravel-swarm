<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\DurableHierarchicalStepResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

class DurableStepCheckpointCoordinator
{
    protected mixed $beforeStepCheckpointHook = null;

    protected mixed $afterStepCheckpointHook = null;

    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DurableRunRecorder $recorder,
        protected DurableHierarchicalCoordinator $hierarchical,
    ) {}

    public function afterStepCheckpointForTesting(?callable $hook): void
    {
        $this->afterStepCheckpointHook = $hook;
    }

    public function beforeStepCheckpointForTesting(?callable $hook): void
    {
        $this->beforeStepCheckpointHook = $hook;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function checkpointAndDispatch(
        array $run,
        Swarm $swarm,
        string $token,
        RunContext $context,
        int $stepLeaseSeconds,
        int $nextStepIndex,
        ?DurableHierarchicalStepResult $hierarchicalResult,
        ?SwarmStep $step,
        callable $dispatchStep,
        callable $dispatchBranch,
        callable $enterDurableBoundary,
    ): void {
        $runId = (string) $run['run_id'];
        $context->mergeMetadata([
            'completed_steps' => $nextStepIndex,
            'total_steps' => $context->metadata['total_steps'] ?? (int) $run['total_steps'],
        ]);

        if (is_callable($this->beforeStepCheckpointHook)) {
            ($this->beforeStepCheckpointHook)($runId, $nextStepIndex);
        }

        if ($hierarchicalResult !== null) {
            if ($hierarchicalResult->branches !== [] && $hierarchicalResult->waitingParentNodeId !== null) {
                $this->hierarchical->checkpointBranchWait($run, $token, $nextStepIndex, $context, $stepLeaseSeconds, $hierarchicalResult);
            } else {
                $this->recorder->checkpointHierarchical($runId, $token, $nextStepIndex, $context, $stepLeaseSeconds, $hierarchicalResult, $step);
            }
        } else {
            $this->recorder->checkpointSequential($runId, $token, $nextStepIndex, $context, $stepLeaseSeconds);
        }

        if (is_callable($this->afterStepCheckpointHook)) {
            ($this->afterStepCheckpointHook)($runId, $nextStepIndex);
        }

        if ($enterDurableBoundary($run, $swarm, $context, $nextStepIndex)) {
            return;
        }

        if ($hierarchicalResult !== null && $hierarchicalResult->branches !== []) {
            $branches = $this->durableRuns->branchesFor($runId, $hierarchicalResult->waitingParentNodeId);

            foreach ($branches as $branch) {
                $dispatch = $dispatchBranch($runId, (string) $branch['branch_id'], $branch['queue_connection'] ?? $run['queue_connection'], $branch['queue_name'] ?? $run['queue_name']);
                unset($dispatch);
            }

            return;
        }

        $dispatch = $dispatchStep($runId, $nextStepIndex, $run['queue_connection'], $run['queue_name']);
        unset($dispatch);
    }
}
