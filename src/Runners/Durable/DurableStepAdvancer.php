<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Runners\DurableHierarchicalStepResult;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Throwable;

class DurableStepAdvancer
{
    public function __construct(
        protected DurableRunContext $runs,
        protected DurableRunStore $durableRuns,
        protected DurableRetryHandler $retryHandler,
        protected DurableHierarchicalCoordinator $hierarchical,
        protected DurableRunTerminalHandler $terminal,
        protected DurableTopLevelParallelAdvancer $parallel,
        protected DurableStepExecutionBuilder $executionBuilder,
        protected DurableSequentialStepAdvancer $sequential,
        protected DurableStepCheckpointCoordinator $checkpoints,
    ) {}

    public function afterStepCheckpointForTesting(?callable $hook): void
    {
        $this->checkpoints->afterStepCheckpointForTesting($hook);
    }

    public function beforeStepCheckpointForTesting(?callable $hook): void
    {
        $this->checkpoints->beforeStepCheckpointForTesting($hook);
    }

    public function advance(
        string $runId,
        int $expectedStepIndex,
        callable $dispatchStep,
        callable $dispatchBranch,
        callable $enterDurableBoundary,
    ): void {
        $run = $this->runs->requireRun($runId);
        $stepLeaseSeconds = $this->runs->validateStepTimeoutSeconds((int) $run['step_timeout_seconds']);
        $token = $this->durableRuns->acquireLease($runId, $expectedStepIndex, $stepLeaseSeconds);

        if ($token === null) {
            return;
        }

        $run = $this->runs->requireRun($runId);
        $context = $this->runs->loadContext($runId);
        $stepLeaseSeconds = $this->runs->validateStepTimeoutSeconds((int) $run['step_timeout_seconds']);

        if ($this->runs->hasTimedOut($run)) {
            try {
                $this->terminal->failTimedOutRun($run, $token, $context, $stepLeaseSeconds, $dispatchStep);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }

            return;
        }

        if (($run['cancel_requested_at'] ?? null) !== null) {
            try {
                $this->terminal->cancelRun($run, $token, $context, $dispatchStep);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }

            return;
        }

        [$swarm, $state] = $this->executionBuilder->build($run, $token, $context, $expectedStepIndex, $stepLeaseSeconds);
        $hierarchicalResult = null;

        try {
            if ($run['topology'] === Topology::Parallel->value) {
                $this->parallel->advance($state, $run, $token, $stepLeaseSeconds, $expectedStepIndex, $dispatchBranch, $dispatchStep);

                return;
            }

            if ($run['topology'] === Topology::Hierarchical->value) {
                $hierarchicalResult = $this->advanceHierarchical($state, $run, $token, $context, $stepLeaseSeconds, $expectedStepIndex, $dispatchStep);

                if ($hierarchicalResult === null) {
                    return;
                }

                $step = $hierarchicalResult->step;
            } else {
                $step = $this->sequential->advance($state, $expectedStepIndex);
            }
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            return;
        } catch (Throwable $exception) {
            $retry = $this->retryHandler->scheduleRunRetryIfAllowed($run, $swarm, $context, $token, $stepLeaseSeconds, $expectedStepIndex, $exception);
            if ($retry['scheduled']) {
                if ($retry['dispatchStep'] !== null) {
                    $dispatch = $dispatchStep($retry['dispatchStep']['runId'], $retry['dispatchStep']['stepIndex'], $retry['dispatchStep']['connection'], $retry['dispatchStep']['queue']);
                    unset($dispatch);
                }

                return;
            }

            try {
                $this->terminal->failRun($run, $token, $exception, $context, $stepLeaseSeconds, $dispatchStep);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }

            throw $exception;
        }

        $run = $this->runs->requireRun($runId);

        if (($run['cancel_requested_at'] ?? null) !== null) {
            try {
                $this->terminal->cancelRun($run, $token, $context, $dispatchStep, $step);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }

            return;
        }

        if (($run['pause_requested_at'] ?? null) !== null) {
            try {
                $this->terminal->pauseRun($run, $token, $context);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }

            return;
        }

        $nextStepIndex = $hierarchicalResult !== null && $hierarchicalResult->nextStepIndex !== null
            ? $hierarchicalResult->nextStepIndex
            : $expectedStepIndex + 1;
        $isComplete = $run['topology'] === Topology::Hierarchical->value
            ? $hierarchicalResult?->complete === true
            : $nextStepIndex >= (int) $run['total_steps'];

        if ($isComplete) {
            try {
                $this->terminal->completeRun($run, $token, $context, $stepLeaseSeconds, $step ?? null, $dispatchStep);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }

            return;
        }

        try {
            $this->checkpoints->checkpointAndDispatch(
                $run,
                $swarm,
                $token,
                $context,
                $stepLeaseSeconds,
                $nextStepIndex,
                $hierarchicalResult,
                $step ?? null,
                $dispatchStep,
                $dispatchBranch,
                $enterDurableBoundary,
            );
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            return;
        }
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function failCurrentRunFromBranchFailures(array $run, string $token, RunContext $context, int $stepLeaseSeconds, ?string $parentNodeId, callable $dispatchStep): void
    {
        $this->terminal->failCurrentRunFromBranchFailures($run, $token, $context, $stepLeaseSeconds, $parentNodeId, $dispatchStep);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function advanceHierarchical(
        SwarmExecutionState $state,
        array $run,
        string $token,
        RunContext $context,
        int $stepLeaseSeconds,
        int $expectedStepIndex,
        callable $dispatchStep,
    ): ?DurableHierarchicalStepResult {
        return $this->hierarchical->runStep(
            $state,
            $run,
            $token,
            $context,
            $stepLeaseSeconds,
            $expectedStepIndex,
            function (array $run, string $token, RunContext $context, int $stepLeaseSeconds, ?string $parentNodeId) use ($dispatchStep): void {
                $this->failCurrentRunFromBranchFailures($run, $token, $context, $stepLeaseSeconds, $parentNodeId, $dispatchStep);
            },
        );
    }
}
