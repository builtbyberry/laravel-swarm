<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Support\BranchWaitPayload;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Throwable;

class DurableStepAdvancer
{
    protected mixed $beforeStepCheckpointHook = null;

    protected mixed $afterStepCheckpointHook = null;

    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected Dispatcher $events,
        protected SequentialRunner $sequential,
        protected DurableRunRecorder $recorder,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
        protected Application $application,
        protected DurableRunContext $runs,
        protected DurablePayloadCapture $payloads,
        protected DurableBranchCoordinator $branches,
        protected DurableChildSwarmCoordinator $children,
        protected DurableRetryHandler $retryHandler,
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
            $exception = new SwarmException("Durable swarm run [{$runId}] exceeded its configured timeout.");
            try {
                $this->recorder->fail($runId, $token, $exception, $context, $stepLeaseSeconds);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->children->markChildTerminalIfNeeded($runId, 'failed', null, [
                'message' => $this->capture->failureMessage($exception),
                'class' => $exception::class,
                'timed_out' => true,
            ], $dispatchStep);
            $this->events->dispatch(new SwarmFailed(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                exception: $this->capture->failureException($exception),
                durationMs: $this->runs->durationMillisecondsFor($runId),
                metadata: $this->payloads->eventMetadata($context),
                executionMode: ExecutionMode::Durable->value,
                exceptionClass: $exception::class,
            ));

            return;
        }

        if (($run['cancel_requested_at'] ?? null) !== null) {
            try {
                $this->recorder->cancel($runId, $token, $context);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->children->markChildTerminalIfNeeded($runId, 'cancelled', null, null, $dispatchStep);
            $this->events->dispatch(new SwarmCancelled(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                metadata: $this->payloads->eventMetadata($context),
                executionMode: ExecutionMode::Durable->value,
            ));

            return;
        }

        $swarm = $this->application->make($run['swarm_class']);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve durable swarm [{$run['swarm_class']}] from the container.");
        }

        $this->connection->transaction(function () use ($runId, $token, $expectedStepIndex, $context, $stepLeaseSeconds): void {
            $this->durableRuns->markRunning($runId, $token, $expectedStepIndex);
            $this->historyStore->syncDurableState($runId, 'running', $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false, $token, $stepLeaseSeconds);
        });

        if ($expectedStepIndex === 0 && $run['current_step_index'] === null) {
            $this->events->dispatch(new SwarmStarted(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                input: $this->capture->input($context->input),
                metadata: $this->payloads->eventMetadata($context),
                executionMode: ExecutionMode::Durable->value,
            ));
        }

        $timeoutSeconds = max((int) ceil((Carbon::parse($run['timeout_at'], 'UTC')->diffInSeconds(now('UTC'), false)) * -1), 1);
        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: Topology::from($run['topology']),
            executionMode: ExecutionMode::Durable,
            deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: (int) $run['total_steps'],
            ttlSeconds: $this->runs->ttlSeconds(),
            leaseSeconds: $stepLeaseSeconds,
            executionToken: $token,
            verifyOwnership: fn (): null => $this->durableRuns->assertOwned($runId, $token),
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
            queueHierarchicalParallelCoordination: null,
        );

        $hierarchicalResult = null;

        try {
            if ($run['topology'] === Topology::Parallel->value) {
                $this->handleParallelStep($state, $run, $token, $stepLeaseSeconds, $expectedStepIndex, $dispatchBranch, $dispatchStep);

                return;
            }

            if ($run['topology'] === Topology::Hierarchical->value) {
                $hierarchicalResult = $this->hierarchical->runStep(
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

                if ($hierarchicalResult === null) {
                    return;
                }

                $step = $hierarchicalResult->step;
            } else {
                $step = $this->sequential->runSingleStep($state, $expectedStepIndex);
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
                $this->recorder->fail($runId, $token, $exception, $context, $stepLeaseSeconds);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->children->markChildTerminalIfNeeded($runId, 'failed', null, [
                'message' => $this->capture->failureMessage($exception),
                'class' => $exception::class,
            ], $dispatchStep);
            $this->events->dispatch(new SwarmFailed(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                exception: $this->capture->failureException($exception),
                durationMs: $this->runs->durationMillisecondsFor($runId),
                metadata: $this->payloads->eventMetadata($context),
                executionMode: ExecutionMode::Durable->value,
                exceptionClass: $exception::class,
            ));

            throw $exception;
        }

        if (($run = $this->runs->requireRun($runId)) && ($run['cancel_requested_at'] ?? null) !== null) {
            try {
                $this->recorder->cancel($runId, $token, $context, $step);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->children->markChildTerminalIfNeeded($runId, 'cancelled', null, null, $dispatchStep);
            $this->events->dispatch(new SwarmCancelled(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                metadata: $this->payloads->eventMetadata($context),
                executionMode: ExecutionMode::Durable->value,
            ));

            return;
        }

        if (($run['pause_requested_at'] ?? null) !== null) {
            try {
                $this->recorder->pauseAtBoundary($runId, $token, $context);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->events->dispatch(new SwarmPaused(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                metadata: $this->payloads->eventMetadata($context),
                executionMode: ExecutionMode::Durable->value,
            ));

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
                $this->completeDurableRun($runId, $run, $token, $context, $stepLeaseSeconds, $step ?? null, $dispatchStep);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }

            return;
        }

        try {
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
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            return;
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

    /**
     * @param  array<string, mixed>  $run
     */
    protected function handleParallelStep(SwarmExecutionState $state, array $run, string $token, int $stepLeaseSeconds, int $expectedStepIndex, callable $dispatchBranch, callable $dispatchStep): void
    {
        if ($expectedStepIndex === 0) {
            $this->startTopLevelParallelBranches($state, $run, $token, $stepLeaseSeconds, $dispatchBranch);

            return;
        }

        $this->joinTopLevelParallelBranches($state, $run, $token, $stepLeaseSeconds, $dispatchStep);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function completeDurableRun(string $runId, array $run, string $token, RunContext $context, int $stepLeaseSeconds, ?SwarmStep $step, callable $dispatchStep): void
    {
        $response = new SwarmResponse(
            output: (string) ($context->data['last_output'] ?? $context->input),
            steps: $step !== null ? [$step] : [],
            usage: is_array($context->metadata['usage'] ?? null) ? $context->metadata['usage'] : [],
            context: $context,
            artifacts: $context->artifacts,
            metadata: array_merge($context->metadata, [
                'run_id' => $runId,
                'topology' => $run['topology'],
            ]),
        );

        $capturedResponse = $this->limits->response($this->capture->response($response));
        $this->recorder->complete($runId, $token, $context, $capturedResponse, $stepLeaseSeconds, $step);
        $this->children->markChildTerminalIfNeeded($runId, 'completed', $capturedResponse->output, null, $dispatchStep);

        $this->events->dispatch(new SwarmCompleted(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            output: $capturedResponse->output,
            durationMs: $this->runs->durationMillisecondsFor($runId),
            metadata: $capturedResponse->metadata,
            artifacts: $capturedResponse->artifacts,
            executionMode: ExecutionMode::Durable->value,
        ));
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function startTopLevelParallelBranches(SwarmExecutionState $state, array $run, string $token, int $stepLeaseSeconds, callable $dispatchBranch): void
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
    protected function joinTopLevelParallelBranches(SwarmExecutionState $state, array $run, string $token, int $stepLeaseSeconds, callable $dispatchStep): void
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
            $this->failCurrentRunFromBranchFailures($run, $token, $state->context, $stepLeaseSeconds, 'parallel', $dispatchStep);

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

        $response = new SwarmResponse(
            output: $output,
            steps: [],
            usage: $usage,
            context: $state->context,
            artifacts: $state->context->artifacts,
            metadata: array_merge($state->context->metadata, [
                'run_id' => $state->context->runId,
                'topology' => $state->topology->value,
            ]),
        );

        $capturedResponse = $this->limits->response($this->capture->response($response));
        $this->recorder->complete($state->context->runId, $token, $state->context, $capturedResponse, $stepLeaseSeconds);
        $this->children->markChildTerminalIfNeeded($state->context->runId, 'completed', $capturedResponse->output, null, $dispatchStep);
        $this->events->dispatch(new SwarmCompleted(
            runId: $state->context->runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            output: $capturedResponse->output,
            durationMs: $this->runs->durationMillisecondsFor($state->context->runId),
            metadata: $capturedResponse->metadata,
            artifacts: $capturedResponse->artifacts,
            executionMode: ExecutionMode::Durable->value,
        ));
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function failCurrentRunFromBranchFailures(array $run, string $token, RunContext $context, int $stepLeaseSeconds, ?string $parentNodeId, callable $dispatchStep): void
    {
        $branches = $this->durableRuns->branchesFor($run['run_id'], $parentNodeId);
        $failed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'failed'));
        $message = 'Durable parallel branches failed: '.implode(', ', array_map(static fn (array $branch): string => (string) $branch['branch_id'], $failed));
        $exception = new SwarmException($message);
        $context->mergeMetadata([
            'durable_parallel_branches' => $this->branches->branchSummaries($branches),
        ]);

        $this->recorder->fail($run['run_id'], $token, $exception, $context, $stepLeaseSeconds);
        $this->children->markChildTerminalIfNeeded($run['run_id'], 'failed', null, [
            'message' => $this->capture->failureMessage($exception),
            'class' => $exception::class,
        ], $dispatchStep);
        $this->events->dispatch(new SwarmFailed(
            runId: $run['run_id'],
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            exception: $this->capture->failureException($exception),
            durationMs: $this->runs->durationMillisecondsFor($run['run_id']),
            metadata: $this->payloads->eventMetadata($context),
            executionMode: $this->runs->publicLifecycleExecutionMode($run),
            exceptionClass: $exception::class,
        ));
    }
}
