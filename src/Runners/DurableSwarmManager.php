<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Attributes\DurableDetails;
use BuiltByBerry\LaravelSwarm\Attributes\DurableLabels;
use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\DurableWait;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ConfiguresDurableRetries;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DispatchesChildSwarms;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableBranches;
use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableWaits;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmChildCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmChildFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmChildStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmProgressRecorded;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Events\SwarmSignalled;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaitTimedOut;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableChildRun;
use BuiltByBerry\LaravelSwarm\Responses\DurableRetryPolicy;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
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
    protected mixed $beforeStepCheckpointHook = null;

    protected mixed $afterStepCheckpointHook = null;

    protected mixed $afterChildIntentHook = null;

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
    ) {}

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

            return new DurableSwarmStart($context->runId, $this->makeStepJob($context->runId, 0, $connection, $queue));
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
            fn (array $branch): array => $this->withBranchRouting($swarm, $context, $branch, $runRow),
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
        return $this->durableRuns->find($runId);
    }

    public function inspect(string $runId): DurableRunDetail
    {
        $run = $this->durableRuns->find($runId);

        if ($run === null) {
            throw new SwarmException("Durable run [{$runId}] was not found.");
        }

        return new DurableRunDetail(
            runId: $runId,
            run: $run,
            history: $this->historyStore->find($runId),
            labels: $this->durableRuns->labels($runId),
            details: $this->durableRuns->details($runId),
            waits: $this->durableRuns->waits($runId),
            signals: $this->durableRuns->signals($runId),
            progress: $this->durableRuns->progress($runId),
            children: $this->durableRuns->childRuns($runId),
            branches: $this->durableRuns->branchesFor($runId),
        );
    }

    /**
     * @param  array<string, bool|int|float|string|null>  $labels
     * @return array<int, DurableRunDetail>
     */
    public function inspectByLabels(array $labels, int $limit = 50): array
    {
        return array_map(
            fn (string $runId): DurableRunDetail => $this->inspect($runId),
            $this->durableRuns->runIdsForLabels($labels, $limit),
        );
    }

    /**
     * @param  array<string, bool|int|float|string|null>  $labels
     */
    public function updateLabels(string $runId, array $labels): void
    {
        $this->requireRun($runId);
        $this->durableRuns->updateLabels($runId, $labels);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function updateDetails(string $runId, array $details): void
    {
        $this->requireRun($runId);
        $this->durableRuns->updateDetails($runId, $this->durablePayload($details));
    }

    public function signal(string $runId, string $name, mixed $payload = null, ?string $idempotencyKey = null): DurableSignalResult
    {
        $run = $this->requireRun($runId);
        $capturedPayload = $this->durablePayload($payload);
        $signal = $this->durableRuns->recordSignal($runId, $name, $capturedPayload, $idempotencyKey);
        $accepted = false;

        if (($signal['duplicate'] ?? false) !== true && $run['status'] === 'waiting') {
            $accepted = $this->durableRuns->releaseWaitWithSignal($runId, $name, (int) $signal['id']);

            if ($accepted) {
                $context = $this->loadContext($runId);
                $signals = is_array($context->metadata['durable_signals'] ?? null) ? $context->metadata['durable_signals'] : [];
                $outcomes = is_array($context->metadata['durable_wait_outcomes'] ?? null) ? $context->metadata['durable_wait_outcomes'] : [];
                $signals[$name] = ['payload' => $capturedPayload, 'signal_id' => $signal['id']];
                $outcomes[$name] = ['status' => 'signalled', 'payload' => $capturedPayload, 'timed_out' => false];
                $context->mergeMetadata([
                    'durable_signals' => $signals,
                    'durable_wait_outcomes' => $outcomes,
                ]);
                $this->contextStore->put($this->capture->activeContext($context), $this->ttlSeconds());
                $this->historyStore->syncDurableState($runId, 'pending', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false);

                $updated = $this->requireRun($runId);
                if ($updated['status'] === 'pending') {
                    $this->dispatchStepJob($runId, (int) $updated['next_step_index'], $updated['queue_connection'], $updated['queue_name']);
                }
            }
        }

        $this->events->dispatch(new SwarmSignalled(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            signalName: $name,
            accepted: $accepted,
            executionMode: $this->publicLifecycleExecutionMode($run),
        ));

        return new DurableSignalResult(
            runId: $runId,
            name: $name,
            status: $accepted ? 'accepted' : (string) ($signal['status'] ?? 'recorded'),
            accepted: $accepted,
            duplicate: (bool) ($signal['duplicate'] ?? false),
            signal: $signal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function wait(string $runId, string $name, ?string $reason = null, ?int $timeoutSeconds = null, array $metadata = []): void
    {
        $run = $this->requireRun($runId);
        $metadata = $this->durablePayload($metadata);
        $this->durableRuns->createWait($runId, $name, $reason, $timeoutSeconds, $metadata);

        $context = $this->loadContext($runId);
        $this->historyStore->syncDurableState($runId, 'waiting', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false);

        $this->events->dispatch(new SwarmWaiting(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            waitName: $name,
            reason: $reason,
            metadata: $context->metadata,
            executionMode: $this->publicLifecycleExecutionMode($run),
        ));
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    public function recordProgress(string $runId, ?string $branchId = null, array $progress = []): void
    {
        $this->requireRun($runId);
        $progress = $this->durablePayload($progress);
        $this->durableRuns->recordProgress($runId, $branchId, $progress);

        $this->events->dispatch(new SwarmProgressRecorded(
            runId: $runId,
            branchId: $branchId,
            progress: $progress,
        ));
    }

    public function dispatchChildSwarm(string $parentRunId, string $childSwarmClass, string|array|RunContext $task, ?string $dedupeKey = null): DurableChildRun
    {
        $parent = $this->requireRun($parentRunId);
        $swarm = $this->application->make($childSwarmClass);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve child swarm [{$childSwarmClass}] from the container.");
        }

        $context = RunContext::fromTask($task);
        $context->mergeMetadata(['parent_run_id' => $parentRunId]);
        $waitName = $this->childWaitName($context->runId);

        $this->persistChildIntent($parent, $context, $childSwarmClass, $waitName, $dedupeKey);

        if (is_callable($this->afterChildIntentHook)) {
            ($this->afterChildIntentHook)($parentRunId, $context->runId);
        }

        $this->dispatchChildIntent([
            'parent_run_id' => $parentRunId,
            'child_run_id' => $context->runId,
            'child_swarm_class' => $childSwarmClass,
            'wait_name' => $waitName,
            'context_payload' => $context->toArray(),
            'status' => 'pending',
        ]);

        $child = $this->durableRuns->childRunForChild($context->runId);

        return new DurableChildRun($parentRunId, $context->runId, $childSwarmClass, (string) ($child['status'] ?? 'pending'));
    }

    protected function persistChildIntent(array $parent, RunContext $childContext, string $childSwarmClass, string $waitName, ?string $dedupeKey = null): void
    {
        $parentRunId = (string) $parent['run_id'];
        $reason = "Waiting for child swarm [{$childContext->runId}].";
        $metadata = $this->durablePayload([
            'child_run_id' => $childContext->runId,
            'child_swarm_class' => $childSwarmClass,
        ]);

        $this->connection->transaction(function () use ($parentRunId, $childContext, $childSwarmClass, $waitName, $reason, $metadata, $dedupeKey): void {
            $parentContext = $this->loadContext($parentRunId);
            $dispatched = is_array($parentContext->metadata['durable_dispatched_child_swarms'] ?? null) ? $parentContext->metadata['durable_dispatched_child_swarms'] : [];
            $dispatched[$dedupeKey ?? $childContext->runId] = true;
            $parentContext->mergeMetadata(['durable_dispatched_child_swarms' => $dispatched]);

            $this->durableRuns->createWait($parentRunId, $waitName, $reason, null, $metadata);
            $this->durableRuns->createChildRun($parentRunId, $childContext->runId, $childSwarmClass, $waitName, $childContext->toArray());
            $this->contextStore->put($this->capture->activeContext($parentContext), $this->ttlSeconds());
            $this->historyStore->syncDurableState($parentRunId, 'waiting', $this->capture->context($parentContext), $parentContext->metadata, $this->ttlSeconds(), false);
        });

        $this->events->dispatch(new SwarmWaiting(
            runId: $parentRunId,
            swarmClass: $parent['swarm_class'],
            topology: $parent['topology'],
            waitName: $waitName,
            reason: $reason,
            metadata: $metadata,
            executionMode: $this->publicLifecycleExecutionMode($parent),
        ));
    }

    public function pause(string $runId): bool
    {
        $run = $this->requireRun($runId);
        $context = null;
        $updated = null;

        $paused = $this->connection->transaction(function () use ($runId, &$context, &$updated): bool {
            if (! $this->durableRuns->pause($runId)) {
                return false;
            }

            $updated = $this->requireRun($runId);

            if ($updated['status'] === 'paused') {
                $context = $this->loadContext($runId);
                $this->historyStore->syncDurableState($runId, 'paused', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false);
            }

            return true;
        });

        if (! $paused) {
            throw new SwarmException("Durable run [{$runId}] cannot be paused from status [{$run['status']}].");
        }

        if (is_array($updated) && $updated['status'] === 'paused' && $context !== null) {
            $this->events->dispatch(new SwarmPaused(
                runId: $runId,
                swarmClass: $updated['swarm_class'],
                topology: $updated['topology'],
                metadata: $context->metadata,
                executionMode: $this->publicLifecycleExecutionMode($updated),
            ));
        }

        return true;
    }

    public function resume(string $runId): bool
    {
        $run = $this->requireRun($runId);

        if ($run['status'] !== 'paused') {
            throw new SwarmException("Durable run [{$runId}] cannot be resumed from status [{$run['status']}].");
        }

        $context = null;
        $updated = null;

        $resumed = $this->connection->transaction(function () use ($runId, &$context, &$updated): bool {
            if (! $this->durableRuns->resume($runId)) {
                return false;
            }

            $updated = $this->requireRun($runId);
            $context = $this->loadContext($runId);
            $this->historyStore->syncDurableState($runId, $updated['status'], $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false);

            return true;
        });

        if (! $resumed || $context === null) {
            throw new SwarmException("Durable run [{$runId}] could not be resumed.");
        }

        $this->events->dispatch(new SwarmResumed(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            metadata: $context->metadata,
            executionMode: $this->publicLifecycleExecutionMode($updated),
        ));

        if (is_array($updated) && $updated['status'] === 'waiting') {
            $this->dispatchWaitingBoundary($updated, true);

            return true;
        }

        if (($updated['coordination_profile'] ?? CoordinationProfile::StepDurable->value) !== CoordinationProfile::QueueHierarchicalParallel->value) {
            $this->dispatchStepJob($runId, (int) ($updated['next_step_index'] ?? $run['next_step_index']), $run['queue_connection'], $run['queue_name']);
        }

        return true;
    }

    public function cancel(string $runId): bool
    {
        $run = $this->requireRun($runId);

        if (in_array($run['status'], ['completed', 'failed', 'cancelled'], true)) {
            throw new SwarmException("Durable run [{$runId}] cannot be cancelled from status [{$run['status']}].");
        }

        $context = null;
        $updated = null;

        $cancelled = $this->connection->transaction(function () use ($runId, &$context, &$updated): bool {
            if (! $this->durableRuns->cancel($runId)) {
                return false;
            }

            $updated = $this->requireRun($runId);

            if ($updated['status'] === 'cancelled') {
                $context = $this->loadContext($runId);
                $this->contextStore->put($this->capture->terminalContext($context), $this->ttlSeconds());
                $this->historyStore->syncDurableState($runId, 'cancelled', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), true);
            }

            return true;
        });

        if (! $cancelled) {
            throw new SwarmException("Durable run [{$runId}] could not be cancelled.");
        }

        if (is_array($updated) && $updated['status'] === 'cancelled' && $context !== null) {
            $this->cancelActiveChildren($runId);

            $this->events->dispatch(new SwarmCancelled(
                runId: $runId,
                swarmClass: $updated['swarm_class'],
                topology: $updated['topology'],
                metadata: $context->metadata,
                executionMode: $this->publicLifecycleExecutionMode($updated),
            ));
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function recover(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        $runs = $this->durableRuns->recoverable(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
            graceSeconds: (int) $this->config->get('swarm.durable.recovery.grace_seconds', 300),
        );

        foreach ($runs as $run) {
            $dispatch = $this->dispatchStepJob($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
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
            $dispatch = $this->dispatchBranchJob($branch['run_id'], $branch['branch_id'], $branch['queue_connection'], $branch['queue_name']);
            unset($dispatch);
        }

        $dueRetryRuns = $this->durableRuns->dueRetries(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($dueRetryRuns as $run) {
            $dispatch = $this->dispatchStepJob($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
            unset($dispatch);
        }

        $dueRetryBranches = $this->durableRuns->dueRetryBranches(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($dueRetryBranches as $branch) {
            $dispatch = $this->dispatchBranchJob($branch['run_id'], $branch['branch_id'], $branch['queue_connection'], $branch['queue_name']);
            unset($dispatch);
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
                    $this->dispatchQueuedHierarchicalResume($run);
                } else {
                    $dispatch = $this->dispatchStepJob($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
                    unset($dispatch);
                }

                $this->durableRuns->markRecoveryDispatched($run['run_id']);
            }
        }

        $timedOutWaits = $this->durableRuns->recoverableWaitTimeouts(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($timedOutWaits as $run) {
            $waitName = $this->latestWaitingName($run['run_id']);

            if ($waitName !== null && $this->durableRuns->releaseTimedOutWait($run['run_id'], $waitName)) {
                $context = $this->loadContext($run['run_id']);
                $outcomes = is_array($context->metadata['durable_wait_outcomes'] ?? null) ? $context->metadata['durable_wait_outcomes'] : [];
                $outcomes[$waitName] = ['status' => 'timed_out', 'payload' => null, 'timed_out' => true];
                $context->mergeMetadata(['durable_wait_outcomes' => $outcomes]);
                $this->contextStore->put($this->capture->activeContext($context), $this->ttlSeconds());
                $this->historyStore->syncDurableState($run['run_id'], 'pending', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false);

                $this->events->dispatch(new SwarmWaitTimedOut(
                    runId: $run['run_id'],
                    swarmClass: $run['swarm_class'],
                    topology: $run['topology'],
                    waitName: $waitName,
                    executionMode: $this->publicLifecycleExecutionMode($run),
                ));

                $dispatch = $this->dispatchStepJob($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
                unset($dispatch);
            }
        }

        $childParents = $this->durableRuns->parentsWaitingOnTerminalChildren(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($childParents as $run) {
            $this->reconcileTerminalChildrenForParent($run);
        }

        $undispatchedChildren = $this->durableRuns->undispatchedChildRuns(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
        );

        foreach ($undispatchedChildren as $child) {
            $this->dispatchChildIntent($child);
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

    public function updateQueueRouting(string $runId, ?string $connection, ?string $queue): void
    {
        $this->durableRuns->updateQueueRouting($runId, $connection, $queue);
    }

    /**
     * @internal Testing hook for crash/recovery coverage. Not part of the public package API.
     */
    public function afterStepCheckpointForTesting(?callable $hook): void
    {
        $this->afterStepCheckpointHook = $hook;
    }

    /**
     * @internal Testing hook for crash/recovery coverage. Not part of the public package API.
     */
    public function beforeStepCheckpointForTesting(?callable $hook): void
    {
        $this->beforeStepCheckpointHook = $hook;
    }

    /**
     * @internal Testing hook for crash/recovery coverage. Not part of the public package API.
     */
    public function afterChildIntentForTesting(?callable $hook): void
    {
        $this->afterChildIntentHook = $hook;
    }

    public function advance(string $runId, int $expectedStepIndex): void
    {
        $run = $this->requireRun($runId);
        $stepLeaseSeconds = $this->validateStepTimeoutSeconds((int) $run['step_timeout_seconds']);
        $token = $this->durableRuns->acquireLease($runId, $expectedStepIndex, $stepLeaseSeconds);

        if ($token === null) {
            return;
        }

        $run = $this->requireRun($runId);
        $context = $this->loadContext($runId);
        $stepLeaseSeconds = $this->validateStepTimeoutSeconds((int) $run['step_timeout_seconds']);

        if ($this->hasTimedOut($run)) {
            $exception = new SwarmException("Durable swarm run [{$runId}] exceeded its configured timeout.");
            try {
                $this->recorder->fail($runId, $token, $exception, $context, $stepLeaseSeconds);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->markChildTerminalIfNeeded($runId, 'failed', null, [
                'message' => $this->capture->failureMessage($exception),
                'class' => $exception::class,
                'timed_out' => true,
            ]);
            $this->events->dispatch(new SwarmFailed(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                exception: $this->capture->failureException($exception),
                durationMs: $this->durationMillisecondsFor($runId),
                metadata: $context->metadata,
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
            $this->markChildTerminalIfNeeded($runId, 'cancelled', null, null);
            $this->events->dispatch(new SwarmCancelled(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                metadata: $context->metadata,
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
            $this->historyStore->syncDurableState($runId, 'running', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false, $token, $stepLeaseSeconds);
        });

        if ($expectedStepIndex === 0 && $run['current_step_index'] === null) {
            $this->events->dispatch(new SwarmStarted(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                input: $this->capture->input($context->input),
                metadata: $context->metadata,
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
            ttlSeconds: $this->ttlSeconds(),
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
                $this->handleParallelStep($state, $run, $token, $stepLeaseSeconds, $expectedStepIndex);

                return;
            }

            if ($run['topology'] === Topology::Hierarchical->value) {
                $hierarchicalResult = $this->handleHierarchicalStep($state, $run, $token, $context, $stepLeaseSeconds, $expectedStepIndex);

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
            if ($this->scheduleRunRetryIfAllowed($run, $swarm, $context, $token, $stepLeaseSeconds, $expectedStepIndex, $exception)) {
                return;
            }

            try {
                $this->recorder->fail($runId, $token, $exception, $context, $stepLeaseSeconds);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->markChildTerminalIfNeeded($runId, 'failed', null, [
                'message' => $this->capture->failureMessage($exception),
                'class' => $exception::class,
            ]);
            $this->events->dispatch(new SwarmFailed(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                exception: $this->capture->failureException($exception),
                durationMs: $this->durationMillisecondsFor($runId),
                metadata: $context->metadata,
                executionMode: ExecutionMode::Durable->value,
                exceptionClass: $exception::class,
            ));

            throw $exception;
        }

        if (($run = $this->requireRun($runId)) && ($run['cancel_requested_at'] ?? null) !== null) {
            try {
                $this->recorder->cancel($runId, $token, $context, $step);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->markChildTerminalIfNeeded($runId, 'cancelled', null, null);
            $this->events->dispatch(new SwarmCancelled(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                metadata: $context->metadata,
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
                metadata: $context->metadata,
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
                $this->completeDurableRun($runId, $run, $token, $context, $stepLeaseSeconds, $step ?? null);
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
                    $this->checkpointHierarchicalBranchWait($run, $token, $nextStepIndex, $context, $stepLeaseSeconds, $hierarchicalResult);
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

        if ($this->enterDeclaredDurableBoundary($run, $swarm, $context, $nextStepIndex)) {
            return;
        }

        if ($hierarchicalResult !== null && $hierarchicalResult->branches !== []) {
            $branches = $this->durableRuns->branchesFor($runId, $hierarchicalResult->waitingParentNodeId);

            foreach ($branches as $branch) {
                $this->dispatchBranchJob($runId, (string) $branch['branch_id'], $branch['queue_connection'] ?? $run['queue_connection'], $branch['queue_name'] ?? $run['queue_name']);
            }

            return;
        }

        $this->dispatchStepJob($runId, $nextStepIndex, $run['queue_connection'], $run['queue_name']);
    }

    protected function handleParallelStep(SwarmExecutionState $state, array $run, string $token, int $stepLeaseSeconds, int $expectedStepIndex): void
    {
        if ($expectedStepIndex === 0) {
            $this->startTopLevelParallelBranches($state, $run, $token, $stepLeaseSeconds);

            return;
        }

        $this->joinTopLevelParallelBranches($state, $run, $token, $stepLeaseSeconds);
    }

    protected function handleHierarchicalStep(SwarmExecutionState $state, array $run, string $token, RunContext $context, int $stepLeaseSeconds, int $expectedStepIndex): ?DurableHierarchicalStepResult
    {
        if (is_string($run['current_node_id'] ?? null) && $this->branchJoinShouldFail($run, $context, $run['current_node_id'])) {
            $this->failCurrentRunFromBranchFailures($run, $token, $context, $stepLeaseSeconds, $run['current_node_id']);

            return null;
        }

        return $this->hierarchical->runDurableStep($state, $expectedStepIndex, $run);
    }

    protected function completeDurableRun(string $runId, array $run, string $token, RunContext $context, int $stepLeaseSeconds, ?SwarmStep $step): void
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
        $this->markChildTerminalIfNeeded($runId, 'completed', $capturedResponse->output, null);

        $this->events->dispatch(new SwarmCompleted(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            output: $capturedResponse->output,
            durationMs: $this->durationMillisecondsFor($runId),
            metadata: $capturedResponse->metadata,
            artifacts: $capturedResponse->artifacts,
            executionMode: ExecutionMode::Durable->value,
        ));
    }

    public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
    {
        return new PendingDispatch($this->makeStepJob($runId, $stepIndex, $connection, $queue));
    }

    public function dispatchBranchJob(string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch
    {
        return new PendingDispatch($this->makeBranchJob($runId, $branchId, $connection, $queue));
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
            if ($this->scheduleBranchRetryIfAllowed($run, $branch, $swarm, $context, $token, $exception)) {
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

            if ($this->parallelFailurePolicy($context) === DurableParallelFailurePolicy::FailRun) {
                $this->failParentFromBranches($run, $context, $stepLeaseSeconds);
            }
        }

        $this->maybeDispatchBranchJoin($runId);
    }

    protected function startTopLevelParallelBranches(SwarmExecutionState $state, array $run, string $token, int $stepLeaseSeconds): void
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
            $branches[] = $this->withBranchRouting($state->swarm, $state->context, $branch, $run);
        }

        $this->connection->transaction(function () use ($token, $state, $stepLeaseSeconds, $branches): void {
            $this->historyStore->syncDurableState($state->context->runId, 'running', $this->capture->context($state->context), $state->context->metadata, $this->ttlSeconds(), false, $token, $stepLeaseSeconds);
            $this->durableRuns->waitForBranches($state->context->runId, new BranchWaitPayload(
                executionToken: $token,
                nextStepIndex: count($branches),
                parentNodeId: 'parallel',
                context: $this->capture->activeContext($state->context),
                ttlSeconds: $this->ttlSeconds(),
                branches: $branches,
            ));
        });

        foreach ($branches as $branch) {
            $this->dispatchBranchJob($state->context->runId, (string) $branch['branch_id'], $branch['queue_connection'] ?? $run['queue_connection'], $branch['queue_name'] ?? $run['queue_name']);
        }
    }

    protected function joinTopLevelParallelBranches(SwarmExecutionState $state, array $run, string $token, int $stepLeaseSeconds): void
    {
        $branches = $this->durableRuns->branchesFor($state->context->runId, 'parallel');

        if (! $this->branchesAreTerminal($branches)) {
            $this->durableRuns->waitForBranches($state->context->runId, new BranchWaitPayload(
                executionToken: $token,
                nextStepIndex: (int) $run['next_step_index'],
                parentNodeId: 'parallel',
                context: $this->capture->activeContext($state->context),
                ttlSeconds: $this->ttlSeconds(),
            ));

            return;
        }

        $completed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'completed'));
        $failed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'failed'));
        $policy = $this->parallelFailurePolicy($state->context);

        if ($failed !== [] && ($policy !== DurableParallelFailurePolicy::PartialSuccess || $completed === [])) {
            $this->failCurrentRunFromBranchFailures($run, $token, $state->context, $stepLeaseSeconds, 'parallel');

            return;
        }

        usort($completed, static fn (array $a, array $b): int => ((int) $a['step_index']) <=> ((int) $b['step_index']));

        $outputs = array_map(static fn (array $branch): string => (string) $branch['output'], $completed);
        $usage = $this->mergeBranchUsage($completed);
        $output = implode("\n\n", $outputs);
        $state->context
            ->mergeData([
                'last_output' => $output,
                'steps' => count($completed),
            ])
            ->mergeMetadata([
                'topology' => $state->topology->value,
                'usage' => $usage,
                'durable_parallel_branches' => $this->branchSummaries($branches),
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
        $this->events->dispatch(new SwarmCompleted(
            runId: $state->context->runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            output: $capturedResponse->output,
            durationMs: $this->durationMillisecondsFor($state->context->runId),
            metadata: $capturedResponse->metadata,
            artifacts: $capturedResponse->artifacts,
            executionMode: ExecutionMode::Durable->value,
        ));
    }

    protected function checkpointHierarchicalBranchWait(array $run, string $token, int $nextStepIndex, RunContext $context, int $stepLeaseSeconds, DurableHierarchicalStepResult $result): void
    {
        $swarm = $this->application->make($run['swarm_class']);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve durable swarm [{$run['swarm_class']}] from the container.");
        }

        $branches = array_map(fn (array $branch): array => $this->withBranchRouting($swarm, $context, $branch, $run), $result->branches);

        $this->connection->transaction(function () use ($run, $token, $nextStepIndex, $context, $stepLeaseSeconds, $result, $branches): void {
            $this->historyStore->syncDurableState($run['run_id'], 'running', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false, $token, $stepLeaseSeconds);
            $this->durableRuns->waitForBranches($run['run_id'], new BranchWaitPayload(
                executionToken: $token,
                nextStepIndex: $nextStepIndex,
                parentNodeId: (string) $result->waitingParentNodeId,
                context: $this->capture->activeContext($context),
                ttlSeconds: $this->ttlSeconds(),
                routeCursor: $result->routeCursor,
                routePlan: $result->routePlan,
                totalSteps: $result->totalSteps,
                branches: $branches,
            ));
        });
    }

    protected function maybeDispatchBranchJoin(string $runId): void
    {
        $run = $this->requireRun($runId);

        $this->dispatchWaitingBoundary($run);
    }

    protected function dispatchWaitingBoundary(array $run, bool $dispatchRecoverableBranches = false): void
    {
        if ($run['status'] !== 'waiting') {
            return;
        }

        $parentNodeId = $run['current_node_id'];

        if (! is_string($parentNodeId)) {
            return;
        }

        $branches = $this->durableRuns->branchesFor($run['run_id'], $parentNodeId);

        if ($this->branchesAreTerminal($branches)) {
            if ($this->durableRuns->releaseWaitingRunForJoin($run['run_id'], (int) $run['next_step_index'])) {
                if (($run['coordination_profile'] ?? CoordinationProfile::StepDurable->value) === CoordinationProfile::QueueHierarchicalParallel->value) {
                    $this->dispatchQueuedHierarchicalResume($run);
                } else {
                    $this->dispatchStepJob($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
                }
            }

            return;
        }

        if (! $dispatchRecoverableBranches) {
            return;
        }

        foreach ($branches as $branch) {
            if (! $this->branchShouldBeRedispatched($branch)) {
                continue;
            }

            $this->dispatchBranchJob($run['run_id'], (string) $branch['branch_id'], $branch['queue_connection'] ?? $run['queue_connection'], $branch['queue_name'] ?? $run['queue_name']);
        }
    }

    protected function branchShouldBeRedispatched(array $branch): bool
    {
        if ($branch['status'] === 'pending') {
            return true;
        }

        if ($branch['status'] !== 'running') {
            return false;
        }

        if (($branch['leased_until'] ?? null) === null) {
            return true;
        }

        return Carbon::parse((string) $branch['leased_until'], 'UTC')->isPast();
    }

    protected function failParentFromBranches(array $run, RunContext $context, int $stepLeaseSeconds): void
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

        $fresh = $this->requireRun($run['run_id']);
        $token = $this->durableRuns->acquireLease($run['run_id'], (int) $fresh['next_step_index'], $stepLeaseSeconds);

        if ($token === null) {
            return;
        }

        $this->failCurrentRunFromBranchFailures($fresh, $token, $context, $stepLeaseSeconds, $parentNodeId);
    }

    protected function failCurrentRunFromBranchFailures(array $run, string $token, RunContext $context, int $stepLeaseSeconds, ?string $parentNodeId): void
    {
        $branches = $this->durableRuns->branchesFor($run['run_id'], $parentNodeId);
        $failed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'failed'));
        $message = 'Durable parallel branches failed: '.implode(', ', array_map(static fn (array $branch): string => (string) $branch['branch_id'], $failed));
        $exception = new SwarmException($message);
        $context->mergeMetadata([
            'durable_parallel_branches' => $this->branchSummaries($branches),
        ]);

        $this->recorder->fail($run['run_id'], $token, $exception, $context, $stepLeaseSeconds);
        $this->events->dispatch(new SwarmFailed(
            runId: $run['run_id'],
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            exception: $this->capture->failureException($exception),
            durationMs: $this->durationMillisecondsFor($run['run_id']),
            metadata: $context->metadata,
            executionMode: $this->publicLifecycleExecutionMode($run),
            exceptionClass: $exception::class,
        ));
    }

    protected function branchJoinShouldFail(array $run, RunContext $context, string $parentNodeId): bool
    {
        $branches = $this->durableRuns->branchesFor($run['run_id'], $parentNodeId);

        if (! $this->branchesAreTerminal($branches)) {
            return false;
        }

        $failed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'failed'));

        if ($failed === []) {
            return false;
        }

        $completed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'completed'));
        $policy = $this->parallelFailurePolicy($context);

        return $policy !== DurableParallelFailurePolicy::PartialSuccess || $completed === [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     */
    protected function branchesAreTerminal(array $branches): bool
    {
        if ($branches === []) {
            return false;
        }

        foreach ($branches as $branch) {
            if (! in_array($branch['status'] ?? null, ['completed', 'failed', 'cancelled'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<string, int>
     */
    protected function mergeBranchUsage(array $branches): array
    {
        $usage = [];

        foreach ($branches as $branch) {
            foreach ((array) ($branch['usage'] ?? []) as $key => $value) {
                if (is_int($value)) {
                    $usage[$key] = ($usage[$key] ?? 0) + $value;
                }
            }
        }

        return $usage;
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<int, array<string, mixed>>
     */
    protected function branchSummaries(array $branches): array
    {
        return array_map(static fn (array $branch): array => [
            'branch_id' => $branch['branch_id'],
            'node_id' => $branch['node_id'],
            'agent_class' => $branch['agent_class'],
            'status' => $branch['status'],
            'failure' => $branch['failure'],
        ], $branches);
    }

    protected function parallelFailurePolicy(RunContext $context): DurableParallelFailurePolicy
    {
        $policy = $context->metadata['durable_parallel_failure_policy'] ?? DurableParallelFailurePolicy::CollectFailures->value;

        return is_string($policy)
            ? DurableParallelFailurePolicy::tryFrom($policy) ?? DurableParallelFailurePolicy::CollectFailures
            : DurableParallelFailurePolicy::CollectFailures;
    }

    /**
     * Public lifecycle events keep `execution_mode: queue` for coordinated queued hierarchical runs.
     *
     * @param  array<string, mixed>  $run
     */
    protected function publicLifecycleExecutionMode(array $run): string
    {
        if (($run['coordination_profile'] ?? CoordinationProfile::StepDurable->value) === CoordinationProfile::QueueHierarchicalParallel->value) {
            return ExecutionMode::Queue->value;
        }

        return ExecutionMode::Durable->value;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function dispatchQueuedHierarchicalResume(array $run): void
    {
        $connection = $this->config->get('swarm.queue.hierarchical_parallel.resume.connection')
            ?? ($run['queue_connection'] ?? null);
        $queue = $this->config->get('swarm.queue.hierarchical_parallel.resume.name')
            ?? ($run['queue_name'] ?? null);
        $dispatch = QueuedHierarchicalCoordinator::dispatchResume($run['run_id'], $connection, $queue);
        unset($dispatch);
    }

    /**
     * @param  array<string, mixed>  $branch
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    protected function withBranchRouting(Swarm $swarm, RunContext $context, array $branch, array $run): array
    {
        if (($run['coordination_profile'] ?? CoordinationProfile::StepDurable->value) === CoordinationProfile::QueueHierarchicalParallel->value) {
            $connection = $this->config->get('swarm.queue.hierarchical_parallel.branch.connection')
                ?? $this->config->get('swarm.queue.hierarchical_parallel.connection')
                ?? $this->config->get('swarm.queue.connection');
            $queue = $this->config->get('swarm.queue.hierarchical_parallel.branch.name')
                ?? $this->config->get('swarm.queue.hierarchical_parallel.name')
                ?? $this->config->get('swarm.queue.name');
        } else {
            $connection = $this->config->get('swarm.durable.parallel.queue.connection');
            $queue = $this->config->get('swarm.durable.parallel.queue.name');

            if ($connection === null) {
                $connection = $run['queue_connection'];
            }

            if ($queue === null) {
                $queue = $run['queue_name'];
            }
        }

        if ($swarm instanceof RoutesDurableBranches) {
            $routing = $swarm->durableBranchQueue($context, $branch);
            $connection = array_key_exists('connection', $routing) ? $routing['connection'] : $connection;
            $queue = array_key_exists('queue', $routing) ? $routing['queue'] : $queue;
        }

        $branch['queue_connection'] = $connection;
        $branch['queue_name'] = $queue;

        return $branch;
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
        $run = $this->durableRuns->find($runId);

        if ($run === null) {
            throw new SwarmException("Durable run [{$runId}] was not found.");
        }

        return $run;
    }

    protected function loadContext(string $runId): RunContext
    {
        $payload = $this->contextStore->find($runId);

        if ($payload === null) {
            throw new SwarmException("Durable run [{$runId}] is missing its persisted context.");
        }

        return RunContext::fromPayload($payload);
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

    protected function scheduleRunRetryIfAllowed(array $run, Swarm $swarm, RunContext $context, string $token, int $stepLeaseSeconds, int $stepIndex, Throwable $exception): bool
    {
        $policy = $this->resolveRetryPolicy($swarm, $this->agentClassForStep($swarm, $run, $stepIndex));

        if ($policy === null || $this->isNonRetryable($policy, $exception)) {
            return false;
        }

        $attempt = ((int) ($run['retry_attempt'] ?? 0)) + 1;

        if ($attempt > $policy->maxAttempts) {
            return false;
        }

        $nextRetryAt = Carbon::now('UTC')->addSeconds($policy->delayForAttempt($attempt));

        try {
            $this->connection->transaction(function () use ($run, $token, $policy, $attempt, $nextRetryAt, $context, $stepLeaseSeconds): void {
                $this->durableRuns->scheduleRetry($run['run_id'], $token, $policy->toArray(), $attempt, $nextRetryAt);
                $this->historyStore->syncDurableState($run['run_id'], 'pending', $this->capture->context($context), array_merge($context->metadata, [
                    'durable_retry_attempt' => $attempt,
                    'durable_next_retry_at' => $nextRetryAt->toJSON(),
                ]), $this->ttlSeconds(), false, $token, $stepLeaseSeconds);
            });
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            return true;
        }

        if ($policy->delayForAttempt($attempt) === 0) {
            $this->dispatchStepJob($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
        }

        return true;
    }

    protected function scheduleBranchRetryIfAllowed(array $run, array $branch, Swarm $swarm, RunContext $context, string $token, Throwable $exception): bool
    {
        $policy = $this->resolveRetryPolicy($swarm, (string) $branch['agent_class']);

        if ($policy === null || $this->isNonRetryable($policy, $exception)) {
            return false;
        }

        $attempt = ((int) ($branch['retry_attempt'] ?? 0)) + 1;

        if ($attempt > $policy->maxAttempts) {
            return false;
        }

        $nextRetryAt = Carbon::now('UTC')->addSeconds($policy->delayForAttempt($attempt));

        try {
            $this->durableRuns->scheduleBranchRetry($run['run_id'], (string) $branch['branch_id'], $token, $policy->toArray(), $attempt, $nextRetryAt);
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            return true;
        }

        if ($policy->delayForAttempt($attempt) === 0) {
            $this->dispatchBranchJob($run['run_id'], (string) $branch['branch_id'], $branch['queue_connection'] ?? $run['queue_connection'], $branch['queue_name'] ?? $run['queue_name']);
        }

        return true;
    }

    protected function resolveRetryPolicy(Swarm $swarm, ?string $agentClass = null): ?DurableRetryPolicy
    {
        if ($agentClass !== null && $swarm instanceof ConfiguresDurableRetries) {
            $policy = $swarm->durableAgentRetryPolicy($agentClass);

            if ($policy instanceof DurableRetryPolicy) {
                return $policy;
            }
        }

        if ($agentClass !== null && class_exists($agentClass)) {
            $attributes = (new ReflectionClass($agentClass))->getAttributes(DurableRetry::class);

            if ($attributes !== []) {
                $retry = $attributes[0]->newInstance();

                return new DurableRetryPolicy($retry->maxAttempts, $retry->backoffSeconds, $retry->nonRetryable);
            }
        }

        if ($swarm instanceof ConfiguresDurableRetries) {
            return $swarm->durableRetryPolicy();
        }

        $attributes = (new ReflectionClass($swarm))->getAttributes(DurableRetry::class);

        if ($attributes !== []) {
            $retry = $attributes[0]->newInstance();

            return new DurableRetryPolicy($retry->maxAttempts, $retry->backoffSeconds, $retry->nonRetryable);
        }

        return null;
    }

    protected function isNonRetryable(DurableRetryPolicy $policy, Throwable $exception): bool
    {
        foreach ($policy->nonRetryable as $class) {
            if (is_a($exception, $class)) {
                return true;
            }
        }

        return false;
    }

    protected function agentClassForStep(Swarm $swarm, array $run, int $stepIndex): ?string
    {
        if ($run['topology'] === Topology::Sequential->value) {
            $agents = $swarm->agents();

            return isset($agents[$stepIndex]) ? $agents[$stepIndex]::class : null;
        }

        return null;
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

    protected function childWaitName(string $childRunId): string
    {
        return 'child:'.$childRunId;
    }

    /**
     * @param  array<string, mixed>  $child
     */
    protected function dispatchChildIntent(array $child): void
    {
        $childRunId = (string) $child['child_run_id'];
        $childSwarmClass = (string) $child['child_swarm_class'];
        $contextPayload = is_array($child['context_payload'] ?? null) ? $child['context_payload'] : [];

        if ($this->durableRuns->find($childRunId) === null) {
            try {
                $swarm = $this->application->make($childSwarmClass);

                if (! $swarm instanceof Swarm) {
                    throw new SwarmException("Unable to resolve child swarm [{$childSwarmClass}] from the container.");
                }

                $response = $this->application->make(SwarmRunner::class)->dispatchDurable($swarm, RunContext::fromPayload($contextPayload));
                unset($response);
            } catch (Throwable $exception) {
                $this->durableRuns->updateChildRun($childRunId, 'failed', null, $this->failurePayload($exception));

                $parent = $this->durableRuns->find((string) $child['parent_run_id']);

                if ($parent !== null) {
                    $this->reconcileTerminalChildrenForParent($parent);
                }

                return;
            }
        }

        $this->durableRuns->markChildRunDispatched($childRunId);

        $this->events->dispatch(new SwarmChildStarted(
            parentRunId: (string) $child['parent_run_id'],
            childRunId: $childRunId,
            childSwarmClass: $childSwarmClass,
        ));
    }

    protected function markChildTerminalIfNeeded(string $childRunId, string $status, ?string $output, ?array $failure): void
    {
        $child = $this->durableRuns->childRunForChild($childRunId);

        if ($child === null || in_array($child['status'], ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $this->durableRuns->updateChildRun($childRunId, $status, $output, $failure);
        $parent = $this->durableRuns->find((string) $child['parent_run_id']);

        if ($parent !== null) {
            $this->reconcileTerminalChildrenForParent($parent);
        }
    }

    protected function reconcileTerminalChildrenForParent(array $parent): void
    {
        foreach ($this->durableRuns->childRuns($parent['run_id']) as $child) {
            if (! in_array($child['status'], ['completed', 'failed', 'cancelled'], true)) {
                continue;
            }

            $waitName = (string) ($child['wait_name'] ?? $this->childWaitName((string) $child['child_run_id']));

            if (! $this->waitIsOpen($parent['run_id'], $waitName)) {
                continue;
            }

            $released = $this->connection->transaction(function () use ($parent, $child, $waitName): bool {
                if (! $this->durableRuns->releaseWaitWithOutcome($parent['run_id'], $waitName, 'child_'.$child['status'], [
                    'status' => $child['status'],
                    'child_run_id' => $child['child_run_id'],
                    'timed_out' => false,
                ])) {
                    return false;
                }

                $context = $this->loadContext($parent['run_id']);
                $children = is_array($context->metadata['durable_child_runs'] ?? null) ? $context->metadata['durable_child_runs'] : [];
                $children[$child['child_run_id']] = [
                    'status' => $child['status'],
                    'child_swarm_class' => $child['child_swarm_class'],
                ];
                $context->mergeMetadata(['durable_child_runs' => $children]);
                $this->contextStore->put($this->capture->activeContext($context), $this->ttlSeconds());
                $this->historyStore->syncDurableState($parent['run_id'], 'pending', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false);

                return $this->durableRuns->markChildTerminalEventDispatched((string) $child['child_run_id']);
            });

            if (! $released) {
                continue;
            }

            if ($child['status'] === 'completed') {
                $this->events->dispatch(new SwarmChildCompleted($parent['run_id'], (string) $child['child_run_id'], (string) $child['child_swarm_class']));
            } else {
                $this->events->dispatch(new SwarmChildFailed($parent['run_id'], (string) $child['child_run_id'], (string) $child['child_swarm_class'], is_array($child['failure'] ?? null) ? $child['failure'] : null));
            }

            $updated = $this->requireRun($parent['run_id']);
            $this->dispatchStepJob($parent['run_id'], (int) $updated['next_step_index'], $updated['queue_connection'], $updated['queue_name']);
        }
    }

    /**
     * @return array{message: string, class: class-string<Throwable>}
     */
    protected function failurePayload(Throwable $exception): array
    {
        return [
            'message' => $this->capture->failureMessage($exception),
            'class' => $exception::class,
        ];
    }

    protected function cancelActiveChildren(string $parentRunId): void
    {
        foreach ($this->durableRuns->childRuns($parentRunId) as $child) {
            if (in_array($child['status'], ['completed', 'failed', 'cancelled'], true)) {
                continue;
            }

            try {
                $this->cancel((string) $child['child_run_id']);
            } catch (SwarmException) {
                $this->durableRuns->updateChildRun((string) $child['child_run_id'], 'cancelled');
            }
        }
    }

    protected function durablePayload(mixed $payload): mixed
    {
        if ($this->capture->capturesInputs() && $this->capture->capturesOutputs()) {
            return $payload;
        }

        if (is_array($payload)) {
            return $this->redactArray($payload);
        }

        return SwarmCapture::REDACTED;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    protected function redactArray(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $redacted[$key] = is_array($value) ? $this->redactArray($value) : SwarmCapture::REDACTED;
        }

        return $redacted;
    }

    protected function hasTimedOut(array $run): bool
    {
        return Carbon::parse($run['timeout_at'], 'UTC')->isPast();
    }

    protected function ttlSeconds(): int
    {
        return (int) $this->config->get('swarm.context.ttl', 3600);
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
        $history = $this->historyStore->find($runId);
        $startedAt = isset($history['started_at']) ? Carbon::parse($history['started_at'], 'UTC') : null;

        if ($startedAt === null) {
            return 1;
        }

        return max((int) $startedAt->diffInMilliseconds(Carbon::now('UTC')), 1);
    }

    protected function makeStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): AdvanceDurableSwarm
    {
        $job = new AdvanceDurableSwarm($runId, $stepIndex);

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        return $job;
    }

    protected function makeBranchJob(string $runId, string $branchId, ?string $connection = null, ?string $queue = null): AdvanceDurableBranch
    {
        $job = new AdvanceDurableBranch($runId, $branchId);

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        return $job;
    }
}
