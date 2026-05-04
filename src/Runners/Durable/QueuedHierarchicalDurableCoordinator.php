<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\QueueHierarchicalParallelBoundary;
use BuiltByBerry\LaravelSwarm\Support\BranchWaitPayload;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use JsonException;

class QueuedHierarchicalDurableCoordinator
{
    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected SwarmCapture $capture,
        protected DurableRunContext $runs,
        protected DurableBranchCoordinator $branches,
        protected DurableJobDispatcher $jobs,
    ) {}

    public function enter(SwarmExecutionState $state, QueueHierarchicalParallelBoundary $boundary): void
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

        $runRow = $this->runs->requireRun($runId);
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
            ttlSeconds: $this->runs->ttlSeconds(),
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
            $this->runs->ttlSeconds(),
            false,
            $state->executionToken,
            $state->leaseSeconds,
        );

        $this->contextStore->put($this->capture->activeContext($context), $this->runs->ttlSeconds());

        $run = $this->runs->requireRun($runId);

        foreach ($this->durableRuns->branchesFor($runId, $boundary->parentParallelNodeId) as $branch) {
            $dispatch = $this->jobs->dispatchBranch(
                $runId,
                (string) $branch['branch_id'],
                $branch['queue_connection'] ?? $run['queue_connection'],
                $branch['queue_name'] ?? $run['queue_name'],
            );
            unset($dispatch);
        }
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

    protected function validateStepTimeoutSeconds(int $seconds): int
    {
        if ($seconds <= 0) {
            throw new SwarmException('Durable swarm step timeout must be a positive integer.');
        }

        return $seconds;
    }
}
