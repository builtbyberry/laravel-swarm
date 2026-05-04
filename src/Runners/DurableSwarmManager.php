<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Attributes\DurableDetails;
use BuiltByBerry\LaravelSwarm\Attributes\DurableLabels;
use BuiltByBerry\LaravelSwarm\Attributes\DurableWait;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DispatchesChildSwarms;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableWaits;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableChildRun;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableChildSwarmCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableLifecycleController;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableManagerCollaboratorFactory;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurablePayloadCapture;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRecoveryCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRetryHandler;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRunContext;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRunInspector;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSignalHandler;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableStepAdvancer;
use BuiltByBerry\LaravelSwarm\Support\BranchWaitPayload;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Carbon;
use JsonException;
use Laravel\Ai\Contracts\Agent;
use ReflectionClass;
use Throwable;

class DurableSwarmManager
{
    protected DurableRunContext $runContext;

    protected DurablePayloadCapture $payloads;

    protected DurableJobDispatcher $jobs;

    protected DurableBranchCoordinator $branches;

    protected DurableChildSwarmCoordinator $children;

    protected DurableLifecycleController $lifecycle;

    protected DurableRecoveryCoordinator $recovery;

    protected DurableHierarchicalCoordinator $hierarchicalCoordinator;

    protected DurableStepAdvancer $advancer;

    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected Dispatcher $events,
        protected SequentialRunner $sequential,
        protected HierarchicalRunner $hierarchical,
        protected DurableRunRecorder $recorder,
        protected SwarmStepRecorder $stepsRecorder,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
        protected Application $application,
        protected DurableRunInspector $inspector,
        protected DurableSignalHandler $signalHandler,
        protected DurableRetryHandler $retryHandler,
    ) {
        $collaborators = $this->application->make(DurableManagerCollaboratorFactory::class)->make(
            $this->config,
            $this->durableRuns,
            $this->historyStore,
            $this->contextStore,
            $this->artifactRepository,
            $this->events,
            $this->sequential,
            $this->hierarchical,
            $this->recorder,
            $this->connection,
            $this->capture,
            $this->limits,
            $this->application,
            $this->retryHandler,
        );

        $this->runContext = $collaborators->runContext;
        $this->payloads = $collaborators->payloads;
        $this->jobs = $collaborators->jobs;
        $this->branches = $collaborators->branches;
        $this->children = $collaborators->children;
        $this->lifecycle = $collaborators->lifecycle;
        $this->recovery = $collaborators->recovery;
        $this->hierarchicalCoordinator = $collaborators->hierarchical;
        $this->advancer = $collaborators->advancer;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): void
    {
        $this->durableRuns->create($payload);
    }

    public function start(Swarm $swarm, RunContext $context, Topology $topology, int $timeoutSeconds, int $totalSteps, DurableParallelFailurePolicy $parallelFailurePolicy = DurableParallelFailurePolicy::CollectFailures): DurableSwarmStart
    {
        $this->limits->checkInput($context->input);

        return $this->connection->transaction(function () use ($swarm, $context, $topology, $timeoutSeconds, $totalSteps, $parallelFailurePolicy): DurableSwarmStart {
            $contextTtl = $this->ttlSeconds();
            $connection = $this->config->get('swarm.durable.queue.connection');
            $queue = $this->config->get('swarm.durable.queue.name');

            $context->mergeMetadata([
                'swarm_class' => $swarm::class,
                'topology' => $topology->value,
                'execution_mode' => ExecutionMode::Durable->value,
                'completed_steps' => 0,
                'total_steps' => $totalSteps,
                'durable_parallel_failure_policy' => $parallelFailurePolicy->value,
            ]);

            $this->applyDurableMetadataAttributes($swarm, $context);

            $this->historyStore->start($context->runId, $swarm::class, $topology->value, $this->capture->context($context), $context->metadata, $contextTtl);
            $this->contextStore->put($this->capture->activeContext($context), $contextTtl);
            $this->historyStore->syncDurableState($context->runId, 'pending', $this->capture->context($context), $context->metadata, $contextTtl, false);

            $this->durableRuns->create([
                'run_id' => $context->runId,
                'swarm_class' => $swarm::class,
                'topology' => $topology->value,
                'execution_mode' => ExecutionMode::Durable->value,
                'status' => 'pending',
                'next_step_index' => 0,
                'current_step_index' => null,
                'total_steps' => $totalSteps,
                'timeout_at' => now('UTC')->addSeconds($timeoutSeconds),
                'step_timeout_seconds' => $this->resolveStepTimeoutSeconds(),
                'execution_token' => null,
                'leased_until' => null,
                'pause_requested_at' => null,
                'cancel_requested_at' => null,
                'queue_connection' => $connection,
                'queue_name' => $queue,
                'finished_at' => null,
                'parent_run_id' => is_string($context->metadata['parent_run_id'] ?? null) ? $context->metadata['parent_run_id'] : null,
            ]);

            if ($context->labels() !== []) {
                $this->durableRuns->updateLabels($context->runId, $context->labels());
            }

            if ($context->details() !== []) {
                $this->durableRuns->updateDetails($context->runId, $context->details());
            }

            return new DurableSwarmStart($context->runId, $this->jobs->makeStepJob($context->runId, 0, $connection, $queue));
        });
    }

    public function enterQueueHierarchicalParallelCoordination(SwarmExecutionState $state, QueueHierarchicalParallelBoundary $boundary): void
    {
        $swarm = $state->swarm;
        $context = $state->context;
        $runId = $context->runId;

        if ($state->executionToken === null || $state->leaseSeconds === null) {
            throw new SwarmException('Queued hierarchical coordination requires a queue execution token and lease.');
        }

        $stepTimeoutSeconds = (int) $this->config->get('swarm.durable.step_timeout', 300);
        $orchestrationTimeout = (int) $this->config->get('swarm.timeout', 300);
        $queueConnection = $this->config->get('swarm.queue.hierarchical_parallel.connection')
            ?? $this->config->get('swarm.queue.connection');
        $queueName = $this->config->get('swarm.queue.hierarchical_parallel.name')
            ?? $this->config->get('swarm.queue.name');

        $existing = $this->durableRuns->find($runId);

        if ($existing === null) {
            $this->durableRuns->create([
                'run_id' => $runId,
                'swarm_class' => $swarm::class,
                'topology' => Topology::Hierarchical->value,
                'execution_mode' => ExecutionMode::Queue->value,
                'coordination_profile' => CoordinationProfile::QueueHierarchicalParallel->value,
                'status' => 'pending',
                'next_step_index' => 0,
                'current_step_index' => null,
                'total_steps' => $boundary->totalSteps,
                'route_plan' => $this->encodeJsonForDurableInsert($boundary->routePlan),
                'route_cursor' => $this->encodeJsonForDurableInsert($boundary->routeCursor),
                'route_start_node_id' => $boundary->routeCursor['route_plan_start'] ?? null,
                'current_node_id' => null,
                'completed_node_ids' => null,
                'node_states' => null,
                'failure' => null,
                'timeout_at' => now('UTC')->addSeconds($orchestrationTimeout),
                'step_timeout_seconds' => $stepTimeoutSeconds,
                'execution_token' => null,
                'leased_until' => null,
                'pause_requested_at' => null,
                'cancel_requested_at' => null,
                'queue_connection' => $queueConnection,
                'queue_name' => $queueName,
                'finished_at' => null,
            ]);
        } elseif (($existing['coordination_profile'] ?? CoordinationProfile::StepDurable->value) !== CoordinationProfile::QueueHierarchicalParallel->value) {
            throw new SwarmException("Swarm run [{$runId}] already has a durable record that is not queued hierarchical coordination.");
        }

        $runRow = $this->requireRun($runId);

        $token = $this->durableRuns->acquireLease($runId, (int) $runRow['next_step_index'], $this->validateStepTimeoutSeconds((int) $runRow['step_timeout_seconds']));

        if ($token === null) {
            throw new SwarmException("Unable to acquire coordination lease for queued hierarchical run [{$runId}].");
        }

        $branches = array_map(
            fn (array $branch): array => $this->branches->withBranchRouting($swarm, $context, $branch, $runRow),
            $boundary->branchDefinitions,
        );

        $context
            ->mergeData([
                'steps' => count($boundary->stepsSoFar),
                'hierarchical_node_outputs' => $boundary->nodeOutputs,
            ])
            ->mergeMetadata([
                'topology' => Topology::Hierarchical->value,
                'coordinator_agent_class' => $boundary->coordinatorClass,
                'route_plan_start' => $boundary->routeCursor['route_plan_start'] ?? null,
                'current_node_id' => $boundary->parentParallelNodeId,
                'completed_node_ids' => $boundary->routeCursor['completed_node_ids'] ?? [],
                'executed_node_ids' => $boundary->executedNodeIds,
                'executed_agent_classes' => $boundary->executedAgentClasses,
                'parallel_groups' => $boundary->parallelGroups,
                'executed_steps' => count($boundary->stepsSoFar),
                'total_steps' => $boundary->totalSteps,
                'usage' => $boundary->mergedUsage,
                'execution_mode' => ExecutionMode::Queue->value,
                'queue_hierarchical_waiting_parallel' => true,
            ]);

        $this->durableRuns->waitForBranches($runId, new BranchWaitPayload(
            executionToken: $token,
            nextStepIndex: $boundary->nextStepIndexAfterJoin,
            parentNodeId: $boundary->parentParallelNodeId,
            context: $this->capture->activeContext($context),
            ttlSeconds: $this->ttlSeconds(),
            routeCursor: $boundary->routeCursor,
            routePlan: $boundary->routePlan,
            totalSteps: $boundary->totalSteps,
            branches: $branches,
        ));

        $this->historyStore->syncDurableState(
            $runId,
            'waiting',
            $this->capture->context($context),
            $context->metadata,
            $this->ttlSeconds(),
            false,
            $state->executionToken,
            $state->leaseSeconds,
        );

        $this->contextStore->put($this->capture->activeContext($context), $this->ttlSeconds());

        $run = $this->requireRun($runId);

        foreach ($this->durableRuns->branchesFor($runId, $boundary->parentParallelNodeId) as $branch) {
            $this->dispatchBranchJob(
                $runId,
                (string) $branch['branch_id'],
                $branch['queue_connection'] ?? $run['queue_connection'],
                $branch['queue_name'] ?? $run['queue_name'],
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array
    {
        return $this->inspector->find($runId);
    }

    public function inspect(string $runId): DurableRunDetail
    {
        return $this->inspector->inspect($runId);
    }

    /**
     * @param  array<string, bool|int|float|string|null>  $labels
     * @return array<int, DurableRunDetail>
     */
    public function inspectByLabels(array $labels, int $limit = 50): array
    {
        return $this->inspector->inspectByLabels($labels, $limit);
    }

    /**
     * @param  array<string, bool|int|float|string|null>  $labels
     */
    public function updateLabels(string $runId, array $labels): void
    {
        $this->inspector->updateLabels($runId, $labels);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function updateDetails(string $runId, array $details): void
    {
        $this->inspector->updateDetails($runId, $details);
    }

    public function signal(string $runId, string $name, mixed $payload = null, ?string $idempotencyKey = null): DurableSignalResult
    {
        $outcome = $this->signalHandler->signal($runId, $name, $payload, $idempotencyKey);

        if ($outcome['dispatchStep'] !== null) {
            $step = $outcome['dispatchStep'];
            $this->dispatchStepJob($step['runId'], $step['stepIndex'], $step['connection'], $step['queue']);
        }

        return $outcome['result'];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function wait(string $runId, string $name, ?string $reason = null, ?int $timeoutSeconds = null, array $metadata = []): void
    {
        $this->signalHandler->wait($runId, $name, $reason, $timeoutSeconds, $metadata);
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    public function recordProgress(string $runId, ?string $branchId = null, array $progress = []): void
    {
        $this->inspector->recordProgress($runId, $branchId, $progress);
    }

    public function dispatchChildSwarm(string $parentRunId, string $childSwarmClass, string|array|RunContext $task, ?string $dedupeKey = null): DurableChildRun
    {
        return $this->children->dispatchChildSwarm(
            $parentRunId,
            $childSwarmClass,
            $task,
            $dedupeKey,
            fn (string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch => $this->dispatchStepJob($runId, $stepIndex, $connection, $queue),
        );
    }

    public function pause(string $runId): bool
    {
        return $this->lifecycle->pause($runId);
    }

    public function resume(string $runId): bool
    {
        $result = $this->lifecycle->resume($runId);

        if ($result['waiting'] !== null) {
            $this->dispatchWaitingBoundary($result['waiting'], true);

            return true;
        }

        if ($result['dispatchStep'] !== null) {
            $step = $result['dispatchStep'];
            $this->dispatchStepJob($step['runId'], $step['stepIndex'], $step['connection'], $step['queue']);
        }

        return true;
    }

    public function cancel(string $runId): bool
    {
        return $this->lifecycle->cancel($runId, fn (string $childRunId): bool => $this->cancel($childRunId));
    }

    /**
     * @return array<int, string>
     */
    public function recover(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        return $this->recovery->recover(
            $runId,
            $swarmClass,
            $limit,
            fn (string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch => $this->dispatchStepJob($runId, $stepIndex, $connection, $queue),
            fn (string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch => $this->dispatchBranchJob($runId, $branchId, $connection, $queue),
        );
    }

    public function updateQueueRouting(string $runId, ?string $connection, ?string $queue): void
    {
        $this->lifecycle->updateQueueRouting($runId, $connection, $queue);
    }

    /**
     * @internal Testing hook for crash/recovery coverage. Not part of the public package API.
     */
    public function afterStepCheckpointForTesting(?callable $hook): void
    {
        $this->advancer->afterStepCheckpointForTesting($hook);
    }

    /**
     * @internal Testing hook for crash/recovery coverage. Not part of the public package API.
     */
    public function beforeStepCheckpointForTesting(?callable $hook): void
    {
        $this->advancer->beforeStepCheckpointForTesting($hook);
    }

    /**
     * @internal Testing hook for crash/recovery coverage. Not part of the public package API.
     */
    public function afterChildIntentForTesting(?callable $hook): void
    {
        $this->children->afterChildIntentForTesting($hook);
    }

    public function advance(string $runId, int $expectedStepIndex): void
    {
        $this->advancer->advance(
            $runId,
            $expectedStepIndex,
            $this->dispatchStepCallback(),
            $this->dispatchBranchCallback(),
            fn (array $run, Swarm $swarm, RunContext $context, int $nextStepIndex): bool => $this->enterDeclaredDurableBoundary($run, $swarm, $context, $nextStepIndex),
        );
    }

    public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
    {
        return $this->jobs->dispatchStep($runId, $stepIndex, $connection, $queue);
    }

    public function dispatchBranchJob(string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch
    {
        return $this->jobs->dispatchBranch($runId, $branchId, $connection, $queue);
    }

    protected function dispatchStepCallback(): callable
    {
        return fn (string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch => $this->dispatchStepJob($runId, $stepIndex, $connection, $queue);
    }

    protected function dispatchBranchCallback(): callable
    {
        return fn (string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch => $this->dispatchBranchJob($runId, $branchId, $connection, $queue);
    }

    public function advanceBranch(string $runId, string $branchId): void
    {
        $run = $this->requireRun($runId);
        $branch = $this->durableRuns->findBranch($runId, $branchId);

        if ($branch === null || in_array($branch['status'], ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        if (($run['cancel_requested_at'] ?? null) !== null || $run['status'] === 'cancelled') {
            $this->durableRuns->cancelBranches($runId, $branch['parent_node_id']);

            return;
        }

        if (($run['pause_requested_at'] ?? null) !== null || $run['status'] === 'paused') {
            return;
        }

        $stepLeaseSeconds = $this->validateStepTimeoutSeconds((int) $run['step_timeout_seconds']);
        $token = $this->durableRuns->acquireBranchLease($runId, $branchId, $stepLeaseSeconds);

        if ($token === null) {
            return;
        }

        $context = $this->loadContext($runId);
        $swarm = $this->application->make($run['swarm_class']);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve durable swarm [{$run['swarm_class']}] from the container.");
        }

        $agent = $this->application->make($branch['agent_class']);

        if (! $agent instanceof Agent) {
            throw new SwarmException("Durable branch agent [{$branch['agent_class']}] must resolve to a Laravel AI agent.");
        }

        $this->durableRuns->markBranchRunning($runId, $branchId, $token);

        $timeoutSeconds = max((int) ceil((Carbon::parse($run['timeout_at'], 'UTC')->diffInSeconds(now('UTC'), false)) * -1), 1);
        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: Topology::from($run['topology']),
            executionMode: ExecutionMode::Durable,
            deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: (int) $run['total_steps'],
            ttlSeconds: $this->ttlSeconds(),
            leaseSeconds: null,
            executionToken: null,
            verifyOwnership: fn (): null => $this->durableRuns->assertBranchOwned($runId, $branchId, $token),
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
            queueHierarchicalParallelCoordination: null,
        );

        $startedAt = MonotonicTime::now();
        $step = null;

        try {
            $this->stepsRecorder->started($state, (int) $branch['step_index'], $branch['agent_class'], $branch['input']);
            $response = $agent->prompt($branch['input']);
            $output = (string) $response;
            $usage = $response->usage->toArray();
            $durationMs = MonotonicTime::elapsedMilliseconds($startedAt);
            $step = $this->stepsRecorder->completed(
                state: $state,
                index: (int) $branch['step_index'],
                agentClass: $branch['agent_class'],
                input: $branch['input'],
                output: $output,
                usage: $usage,
                durationMs: $durationMs,
                metadata: is_array($branch['metadata'] ?? null) ? $branch['metadata'] : [],
                updateContext: false,
                storeContext: false,
                storeArtifacts: false,
            );

            $this->connection->transaction(function () use ($runId, $branch, $branchId, $token, $output, $usage, $durationMs, $step): void {
                if (is_string($branch['node_id'] ?? null)) {
                    $this->durableRuns->storeHierarchicalNodeOutput($runId, $branch['node_id'], $output, $this->ttlSeconds());
                }

                $this->persistBranchStepArtifacts($runId, $step);
                $this->durableRuns->markBranchCompleted($runId, $branchId, $token, $output, $usage, $durationMs);
            });
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            return;
        } catch (Throwable $exception) {
            $retry = $this->retryHandler->scheduleBranchRetryIfAllowed($run, $branch, $swarm, $context, $token, $exception);
            if ($retry['scheduled']) {
                if ($retry['dispatchBranch'] !== null) {
                    $this->dispatchBranchJob($retry['dispatchBranch']['runId'], $retry['dispatchBranch']['branchId'], $retry['dispatchBranch']['connection'], $retry['dispatchBranch']['queue']);
                }

                return;
            }

            try {
                $this->durableRuns->markBranchFailed($runId, $branchId, $token, [
                    'message' => $this->capture->failureMessage($exception),
                    'class' => $exception::class,
                ]);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }

            if ($this->branches->parallelFailurePolicy($context) === DurableParallelFailurePolicy::FailRun) {
                $this->hierarchicalCoordinator->failWaitingParentFromBranches(
                    $run,
                    $context,
                    $stepLeaseSeconds,
                    function (array $run, string $token, RunContext $context, int $stepLeaseSeconds, ?string $parentNodeId): void {
                        $this->advancer->failCurrentRunFromBranchFailures($run, $token, $context, $stepLeaseSeconds, $parentNodeId, $this->dispatchStepCallback());
                    },
                );
            }
        }

        $this->maybeDispatchBranchJoin($runId);
    }

    protected function maybeDispatchBranchJoin(string $runId): void
    {
        $run = $this->requireRun($runId);

        $this->dispatchWaitingBoundary($run);
    }

    protected function dispatchWaitingBoundary(array $run, bool $dispatchRecoverableBranches = false): void
    {
        $this->hierarchicalCoordinator->dispatchWaitingBoundary(
            $run,
            $dispatchRecoverableBranches,
            $this->dispatchStepCallback(),
            $this->dispatchBranchCallback(),
            function (array $run): void {
                $this->dispatchQueuedHierarchicalResume($run);
            },
        );
    }

    /**
     * Public lifecycle events keep `execution_mode: queue` for coordinated queued hierarchical runs.
     *
     * @param  array<string, mixed>  $run
     */
    protected function publicLifecycleExecutionMode(array $run): string
    {
        return $this->runContext->publicLifecycleExecutionMode($run);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function dispatchQueuedHierarchicalResume(array $run): void
    {
        $this->jobs->dispatchQueuedHierarchicalResume($run);
    }

    protected function persistBranchStepArtifacts(string $runId, ?SwarmStep $step): void
    {
        if ($step === null || ! $this->capture->capturesArtifacts()) {
            return;
        }

        $this->artifactRepository->storeMany($runId, $step->artifacts, $this->ttlSeconds());
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     */
    protected function encodeJsonForDurableInsert(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SwarmException('Failed to encode coordination payload for durable insert.', previous: $exception);
        }
    }

    protected function requireRun(string $runId): array
    {
        return $this->runContext->requireRun($runId);
    }

    protected function loadContext(string $runId): RunContext
    {
        return $this->runContext->loadContext($runId);
    }

    protected function latestWaitingName(string $runId): ?string
    {
        $waits = array_reverse($this->durableRuns->waits($runId));

        foreach ($waits as $wait) {
            if (($wait['status'] ?? null) === 'waiting' && is_string($wait['name'] ?? null)) {
                return $wait['name'];
            }
        }

        return null;
    }

    protected function applyDurableMetadataAttributes(Swarm $swarm, RunContext $context): void
    {
        $reflection = new ReflectionClass($swarm);
        $labels = $reflection->getAttributes(DurableLabels::class);
        $details = $reflection->getAttributes(DurableDetails::class);

        if ($labels !== []) {
            $context->withLabels($labels[0]->newInstance()->labels);
        }

        if ($details !== []) {
            $context->withDetails($details[0]->newInstance()->details);
        }
    }

    protected function enterDeclaredDurableBoundary(array $run, Swarm $swarm, RunContext $context, int $nextStepIndex): bool
    {
        foreach ($this->declaredWaits($swarm, $context) as $wait) {
            $name = $wait['name'];
            if ($context->waitOutcome($name) !== null || $this->waitIsOpen($run['run_id'], $name)) {
                continue;
            }

            $this->wait($run['run_id'], $name, $wait['reason'] ?? null, $wait['timeout'] ?? null, $wait['metadata'] ?? []);

            return true;
        }

        if ($swarm instanceof DispatchesChildSwarms) {
            $dispatched = is_array($context->metadata['durable_dispatched_child_swarms'] ?? null) ? $context->metadata['durable_dispatched_child_swarms'] : [];

            foreach ($swarm->durableChildSwarms($context) as $index => $definition) {
                if (isset($dispatched[$index])) {
                    continue;
                }

                $this->dispatchChildSwarm($run['run_id'], $definition['swarm'], $definition['task'], (string) $index);

                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{name: string, timeout?: int|null, reason?: string|null, metadata?: array<string, mixed>}>
     */
    protected function declaredWaits(Swarm $swarm, RunContext $context): array
    {
        if ($swarm instanceof RoutesDurableWaits) {
            return $swarm->durableWaits($context);
        }

        return array_map(
            static fn (\ReflectionAttribute $attribute): array => [
                'name' => $attribute->newInstance()->name,
                'timeout' => $attribute->newInstance()->timeout,
                'reason' => $attribute->newInstance()->reason,
                'metadata' => [],
            ],
            (new ReflectionClass($swarm))->getAttributes(DurableWait::class),
        );
    }

    protected function waitIsOpen(string $runId, string $name): bool
    {
        foreach ($this->durableRuns->waits($runId) as $wait) {
            if (($wait['name'] ?? null) === $name && ($wait['status'] ?? null) === 'waiting') {
                return true;
            }
        }

        return false;
    }

    protected function durablePayload(mixed $payload): mixed
    {
        return $this->payloads->payload($payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function capturedEventMetadata(RunContext $context): array
    {
        return $this->payloads->eventMetadata($context);
    }

    protected function hasTimedOut(array $run): bool
    {
        return Carbon::parse($run['timeout_at'], 'UTC')->isPast();
    }

    protected function ttlSeconds(): int
    {
        return $this->runContext->ttlSeconds();
    }

    protected function resolveStepTimeoutSeconds(): int
    {
        return $this->validateStepTimeoutSeconds((int) $this->config->get('swarm.durable.step_timeout', 300));
    }

    protected function validateStepTimeoutSeconds(int $seconds): int
    {
        if ($seconds <= 0) {
            throw new SwarmException('Durable swarm step timeout must be a positive integer.');
        }

        return $seconds;
    }

    protected function durationMillisecondsFor(string $runId): int
    {
        return $this->runContext->durationMillisecondsFor($runId);
    }
}
