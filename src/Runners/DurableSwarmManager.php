<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableChildRun;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBoundaryCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchAdvancer;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableChildSwarmCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableLifecycleController;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableManagerCollaboratorFactory;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRecoveryCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRetryHandler;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRunInspector;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSignalHandler;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableStepAdvancer;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSwarmStarter;
use BuiltByBerry\LaravelSwarm\Runners\Durable\QueuedHierarchicalDurableCoordinator;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Bus\PendingDispatch;

// DurableSignalHandler, DurableRetryHandler, DurableRunInspector, and DurableRunRecorder are
// intentionally NOT constructor-injected. They are built by DurableManagerCollaboratorFactory
// so that all graph members share a single DurableRunContext and DurablePayloadCapture instance.

/**
 * @phpstan-import-type SwarmTaskInput from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class DurableSwarmManager
{
    protected DurableSignalHandler $signalHandler;

    protected DurableRetryHandler $retryHandler;

    protected DurableRunInspector $inspector;

    protected DurableRunRecorder $recorder;

    protected DurableJobDispatcher $jobs;

    protected DurableSwarmStarter $starter;

    protected QueuedHierarchicalDurableCoordinator $queuedHierarchical;

    protected DurableBoundaryCoordinator $boundary;

    protected DurableBranchCoordinator $branches;

    protected DurableChildSwarmCoordinator $children;

    protected DurableLifecycleController $lifecycle;

    protected DurableRecoveryCoordinator $recovery;

    protected DurableHierarchicalCoordinator $hierarchicalCoordinator;

    protected DurableStepAdvancer $advancer;

    protected DurableBranchAdvancer $branchAdvancer;

    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected Dispatcher $events,
        protected SequentialRunner $sequential,
        protected HierarchicalRunner $hierarchical,
        protected SwarmStepRecorder $stepsRecorder,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
        protected Application $application,
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
            $this->stepsRecorder,
            $this->connection,
            $this->capture,
            $this->limits,
            $this->application,
        );

        $this->signalHandler = $collaborators->signalHandler;
        $this->retryHandler = $collaborators->retryHandler;
        $this->inspector = $collaborators->inspector;
        $this->recorder = $collaborators->recorder;
        $this->jobs = $collaborators->jobs;
        $this->starter = $collaborators->starter;
        $this->queuedHierarchical = $collaborators->queuedHierarchical;
        $this->boundary = $collaborators->boundary;
        $this->branches = $collaborators->branches;
        $this->children = $collaborators->children;
        $this->lifecycle = $collaborators->lifecycle;
        $this->recovery = $collaborators->recovery;
        $this->hierarchicalCoordinator = $collaborators->hierarchical;
        $this->advancer = $collaborators->advancer;
        $this->branchAdvancer = $collaborators->branchAdvancer;
    }

    public function start(Swarm $swarm, RunContext $context, Topology $topology, int $timeoutSeconds, int $totalSteps, DurableParallelFailurePolicy $parallelFailurePolicy = DurableParallelFailurePolicy::CollectFailures): DurableSwarmStart
    {
        return $this->starter->start($swarm, $context, $topology, $timeoutSeconds, $totalSteps, $parallelFailurePolicy);
    }

    public function enterQueueHierarchicalParallelCoordination(SwarmExecutionState $state, QueueHierarchicalParallelBoundary $boundary): void
    {
        $this->queuedHierarchical->enter($state, $boundary);
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
            $this->jobs->dispatchStep($step['runId'], $step['stepIndex'], $step['connection'], $step['queue']);
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

    /**
     * @param  SwarmTaskInput  $task
     */
    public function dispatchChildSwarm(string $parentRunId, string $childSwarmClass, string|array|RunContext $task, ?string $dedupeKey = null): DurableChildRun
    {
        return $this->children->dispatchChildSwarm(
            $parentRunId,
            $childSwarmClass,
            $task,
            $dedupeKey,
            fn (string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch => $this->jobs->dispatchStep($runId, $stepIndex, $connection, $queue),
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
            $this->jobs->dispatchStep($step['runId'], $step['stepIndex'], $step['connection'], $step['queue']);
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
            fn (string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch => $this->jobs->dispatchStep($runId, $stepIndex, $connection, $queue),
            fn (string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch => $this->jobs->dispatchBranch($runId, $branchId, $connection, $queue),
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

    protected function dispatchStepCallback(): callable
    {
        return fn (string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch => $this->jobs->dispatchStep($runId, $stepIndex, $connection, $queue);
    }

    protected function dispatchBranchCallback(): callable
    {
        return fn (string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch => $this->jobs->dispatchBranch($runId, $branchId, $connection, $queue);
    }

    public function advanceBranch(string $runId, string $branchId): void
    {
        $this->branchAdvancer->advanceBranch(
            $runId,
            $branchId,
            $this->dispatchBranchCallback(),
            $this->dispatchStepCallback(),
            /** @param array<string, mixed> $run */
            function (array $run): void {
                $this->dispatchQueuedHierarchicalResume($run);
            },
            /**
             * @param  array<string, mixed>  $run
             */
            function (array $run, string $token, RunContext $context, int $stepLeaseSeconds, ?string $parentNodeId, callable $dispatchStep): void {
                $this->advancer->failCurrentRunFromBranchFailures($run, $token, $context, $stepLeaseSeconds, $parentNodeId, $dispatchStep);
            },
        );
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function dispatchWaitingBoundary(array $run, bool $dispatchRecoverableBranches = false): void
    {
        $this->hierarchicalCoordinator->dispatchWaitingBoundary(
            $run,
            $dispatchRecoverableBranches,
            $this->dispatchStepCallback(),
            $this->dispatchBranchCallback(),
            /** @param array<string, mixed> $run */
            function (array $run): void {
                $this->dispatchQueuedHierarchicalResume($run);
            },
        );
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function dispatchQueuedHierarchicalResume(array $run): void
    {
        $this->jobs->dispatchQueuedHierarchicalResume($run);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function enterDeclaredDurableBoundary(array $run, Swarm $swarm, RunContext $context, int $nextStepIndex): bool
    {
        return $this->boundary->enterDeclaredBoundary($run, $swarm, $context, $this->dispatchStepCallback());
    }
}
