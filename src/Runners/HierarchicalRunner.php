<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Concerns\MergesAgentUsage;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalFinishNode;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalParallelNode;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalWorkerNode;
use BuiltByBerry\LaravelSwarm\Runners\Concerns\AlignsQueuedHierarchicalParallelCursor;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Laravel\Ai\Contracts\Agent;

class HierarchicalRunner
{
    use AlignsQueuedHierarchicalParallelCursor;
    use MergesAgentUsage;

    public function __construct(
        protected HierarchicalRoutePlanner $planner,
        protected ConcurrencyManager $concurrency,
        protected SwarmStepRecorder $stepsRecorder,
        protected SwarmCapture $capture,
        protected DurableRunStore $durableRuns,
    ) {}

    public function run(SwarmExecutionState $state): SwarmResponse
    {
        $agents = $state->swarm->agents();

        if ($agents === []) {
            throw new SwarmException('Hierarchical swarms must define at least one agent.');
        }

        /** @var Agent $coordinator */
        $coordinator = array_shift($agents);
        $this->planner->assertCoordinatorCanPlan($coordinator);
        $this->ensureUniqueWorkerClasses($state->swarm::class, $agents);
        $workerMap = $this->workerMap($agents);

        $steps = [];
        $mergedUsage = [];
        $executedNodeIds = [];
        $executedAgentClasses = [];
        $parallelGroups = [];
        $nodeOutputs = [];
        $nextIndex = 1;

        $coordinatorStep = $this->executeAgent(
            state: $state,
            agent: $coordinator,
            input: $state->context->input,
            index: 0,
            metadata: ['node_role' => 'coordinator'],
        );

        $steps[] = $coordinatorStep;
        $mergedUsage = $this->mergeUsage($mergedUsage, (array) ($coordinatorStep->metadata['usage'] ?? []));

        $plan = $this->planner->fromCoordinatorOutput($coordinator, $agents, $coordinatorStep->output, $state->swarm::class);
        $this->ensurePlanWithinExecutionBudget($state, $plan);

        $deferral = null;
        $finalOutput = $this->executePlan(
            $state,
            $plan,
            $coordinator::class,
            $workerMap,
            $steps,
            $mergedUsage,
            $executedNodeIds,
            $executedAgentClasses,
            $parallelGroups,
            $nodeOutputs,
            $nextIndex,
            $deferral,
            null,
        );

        $state->context
            ->mergeData([
                'last_output' => $finalOutput,
                'steps' => count($steps),
                'hierarchical_node_outputs' => $nodeOutputs,
            ])
            ->mergeMetadata([
                'topology' => $state->topology->value,
                'coordinator_agent_class' => $coordinator::class,
                'route_plan_start' => $plan->startAt,
                'executed_node_ids' => $executedNodeIds,
                'executed_agent_classes' => $executedAgentClasses,
                'parallel_groups' => $parallelGroups,
                'executed_steps' => count($steps),
                'execution_mode' => $state->executionMode->value,
            ]);

        $state->contextStore->put($this->capture->activeContext($state->context), $state->ttlSeconds);

        return new SwarmResponse(
            output: $finalOutput,
            steps: $steps,
            usage: $mergedUsage,
            context: $state->context,
            artifacts: $state->context->artifacts,
            metadata: [
                'run_id' => $state->context->runId,
                'topology' => $state->topology->value,
                'coordinator_agent_class' => $coordinator::class,
                'route_plan_start' => $plan->startAt,
                'executed_node_ids' => $executedNodeIds,
                'executed_agent_classes' => $executedAgentClasses,
                'parallel_groups' => $parallelGroups,
                'executed_steps' => count($steps),
                'execution_mode' => $state->executionMode->value,
            ],
        );
    }

    /**
     * Queued hierarchical execution with multi-worker parallel route segments.
     * Returns a boundary when the plan reaches a parallel node; otherwise returns the completed response.
     */
    public function runQueuedWithCoordination(SwarmExecutionState $state): SwarmResponse|QueueHierarchicalParallelBoundary
    {
        $agents = $state->swarm->agents();

        if ($agents === []) {
            throw new SwarmException('Hierarchical swarms must define at least one agent.');
        }

        /** @var Agent $coordinator */
        $coordinator = array_shift($agents);
        $this->planner->assertCoordinatorCanPlan($coordinator);
        $this->ensureUniqueWorkerClasses($state->swarm::class, $agents);
        $workerMap = $this->workerMap($agents);

        $steps = [];
        $mergedUsage = [];
        $executedNodeIds = [];
        $executedAgentClasses = [];
        $parallelGroups = [];
        $nodeOutputs = [];
        $nextIndex = 1;

        $coordinatorStep = $this->executeAgent(
            state: $state,
            agent: $coordinator,
            input: $state->context->input,
            index: 0,
            metadata: ['node_role' => 'coordinator'],
        );

        $steps[] = $coordinatorStep;
        $mergedUsage = $this->mergeUsage($mergedUsage, (array) ($coordinatorStep->metadata['usage'] ?? []));

        $plan = $this->planner->fromCoordinatorOutput($coordinator, $agents, $coordinatorStep->output, $state->swarm::class);
        $this->ensurePlanWithinExecutionBudget($state, $plan);

        $deferral = null;
        $finalOutput = $this->executePlan(
            $state,
            $plan,
            $coordinator::class,
            $workerMap,
            $steps,
            $mergedUsage,
            $executedNodeIds,
            $executedAgentClasses,
            $parallelGroups,
            $nodeOutputs,
            $nextIndex,
            $deferral,
            null,
        );

        if ($deferral instanceof QueueHierarchicalParallelBoundary) {
            return $deferral;
        }

        $state->context
            ->mergeData([
                'last_output' => $finalOutput,
                'steps' => count($steps),
                'hierarchical_node_outputs' => $nodeOutputs,
            ])
            ->mergeMetadata([
                'topology' => $state->topology->value,
                'coordinator_agent_class' => $coordinator::class,
                'route_plan_start' => $plan->startAt,
                'executed_node_ids' => $executedNodeIds,
                'executed_agent_classes' => $executedAgentClasses,
                'parallel_groups' => $parallelGroups,
                'executed_steps' => count($steps),
                'execution_mode' => $state->executionMode->value,
            ]);

        $state->contextStore->put($this->capture->activeContext($state->context), $state->ttlSeconds);

        return new SwarmResponse(
            output: $finalOutput,
            steps: $steps,
            usage: $mergedUsage,
            context: $state->context,
            artifacts: $state->context->artifacts,
            metadata: [
                'run_id' => $state->context->runId,
                'topology' => $state->topology->value,
                'coordinator_agent_class' => $coordinator::class,
                'route_plan_start' => $plan->startAt,
                'executed_node_ids' => $executedNodeIds,
                'executed_agent_classes' => $executedAgentClasses,
                'parallel_groups' => $parallelGroups,
                'executed_steps' => count($steps),
                'execution_mode' => $state->executionMode->value,
            ],
        );
    }

    /**
     * Resume a queued hierarchical run after coordinated parallel branches join.
     *
     * @param  array<string, mixed>  $durableRun
     */
    public function continueQueuedAfterParallelJoin(SwarmExecutionState $state, array $durableRun): SwarmResponse|QueueHierarchicalParallelBoundary
    {
        $agents = $state->swarm->agents();

        if ($agents === []) {
            throw new SwarmException('Hierarchical swarms must define at least one agent.');
        }

        /** @var Agent $coordinator */
        $coordinator = array_shift($agents);
        $this->planner->assertCoordinatorCanPlan($coordinator);
        $this->ensureUniqueWorkerClasses($state->swarm::class, $agents);
        $workerMap = $this->workerMap($agents);

        $planPayload = $durableRun['route_plan'] ?? null;
        if (! is_array($planPayload)) {
            throw new SwarmException("Queued hierarchical coordination run [{$state->context->runId}] is missing its route plan.");
        }
        $plan = HierarchicalRoutePlan::fromArray($planPayload);
        $cursor = $this->durableCursor($state, $durableRun);
        $parentNodeId = (string) ($durableRun['current_node_id'] ?? '');

        if ($parentNodeId === '' || ! ($plan->node($parentNodeId) instanceof HierarchicalParallelNode)) {
            throw new SwarmException('Queued hierarchical resume requires a valid parallel parent node id on the coordination run.');
        }

        /** @var HierarchicalParallelNode $parallel */
        $parallel = $plan->node($parentNodeId);
        $branches = $this->durableRuns->branchesFor($state->context->runId, $parallel->id);

        if (! $this->branchesAreTerminal($branches)) {
            throw new SwarmException('Queued hierarchical resume invoked before parallel branches are terminal.');
        }

        $nodeOutputs = $this->durableNodeOutputsForCursor($state, $plan, $cursor);
        $branchUsage = [];
        foreach ($branches as $branch) {
            if (($branch['status'] ?? null) === 'completed' && is_string($branch['node_id'] ?? null) && is_string($branch['output'] ?? null)) {
                $nodeOutputs[$branch['node_id']] = $branch['output'];
            }

            if (($branch['status'] ?? null) === 'completed' && is_array($branch['usage'] ?? null)) {
                $branchUsage = $this->mergeUsage($branchUsage, $branch['usage']);
            }
        }
        $nodeOutputs = array_merge(
            is_array($state->context->data['hierarchical_node_outputs'] ?? null)
                ? $state->context->data['hierarchical_node_outputs']
                : [],
            $nodeOutputs,
        );

        $cursor['executed_node_ids'][] = $parallel->id;
        $cursor['completed_node_ids'][] = $parallel->id;
        $cursor['parallel_groups'][] = ['node_id' => $parallel->id, 'branches' => $parallel->branches];
        foreach ($branches as $branch) {
            if (($branch['status'] ?? null) !== 'completed') {
                continue;
            }

            if (is_string($branch['node_id'] ?? null)) {
                $cursor['executed_node_ids'][] = $branch['node_id'];
                $cursor['completed_node_ids'][] = $branch['node_id'];
            }

            if (is_string($branch['agent_class'] ?? null)) {
                $cursor['executed_agent_classes'][] = $branch['agent_class'];
            }
        }
        $cursor['offset'] += 1 + count($parallel->branches);

        $this->advanceDurableCursorToNextWorker($state, $plan, $cursor, $nodeOutputs);
        $this->applyDurableCursorToContext($state, $cursor);
        $state->context->mergeMetadata([
            'usage' => $this->mergeUsage(
                is_array($state->context->metadata['usage'] ?? null) ? $state->context->metadata['usage'] : [],
                $branchUsage,
            ),
        ]);

        $steps = [];
        $history = $state->historyStore->find($state->context->runId);
        $priorStepCount = 0;
        if (is_array($history) && isset($history['steps']) && is_array($history['steps'])) {
            $priorStepCount = count($history['steps']);
        }

        $mergedUsage = is_array($state->context->metadata['usage'] ?? null) ? $state->context->metadata['usage'] : [];
        $executedNodeIds = is_array($state->context->metadata['executed_node_ids'] ?? null) ? $state->context->metadata['executed_node_ids'] : [];
        $executedAgentClasses = is_array($state->context->metadata['executed_agent_classes'] ?? null) ? $state->context->metadata['executed_agent_classes'] : [];
        $parallelGroups = is_array($state->context->metadata['parallel_groups'] ?? null) ? $state->context->metadata['parallel_groups'] : [];
        $nextIndex = $priorStepCount;

        $deferral = null;
        $currentNodeId = $cursor['current_node_id'] !== null ? (string) $cursor['current_node_id'] : null;

        if ($currentNodeId === null && $this->isDurableCursorComplete($cursor)) {
            $finalOutput = (string) ($cursor['final_output'] ?? $state->context->data['last_output'] ?? '');

            return $this->finalizeQueuedHierarchicalResponse(
                $state,
                $plan,
                $coordinator,
                $steps,
                $mergedUsage,
                $executedNodeIds,
                $executedAgentClasses,
                $parallelGroups,
                $nodeOutputs,
                $finalOutput,
            );
        }

        if ($currentNodeId === null) {
            throw new SwarmException('Queued hierarchical resume reached an unexpected cursor state.');
        }

        $finalOutput = $this->executePlan(
            $state,
            $plan,
            $coordinator::class,
            $workerMap,
            $steps,
            $mergedUsage,
            $executedNodeIds,
            $executedAgentClasses,
            $parallelGroups,
            $nodeOutputs,
            $nextIndex,
            $deferral,
            $currentNodeId,
        );

        if ($deferral instanceof QueueHierarchicalParallelBoundary) {
            return $deferral;
        }

        return $this->finalizeQueuedHierarchicalResponse(
            $state,
            $plan,
            $coordinator,
            $steps,
            $mergedUsage,
            $executedNodeIds,
            $executedAgentClasses,
            $parallelGroups,
            $nodeOutputs,
            $finalOutput,
        );
    }

    /**
     * @param  array<int, SwarmStep>  $steps
     * @param  array<string, int>  $mergedUsage
     * @param  array<int, string>  $executedNodeIds
     * @param  array<int, string>  $executedAgentClasses
     * @param  array<int, array{node_id: string, branches: array<int, string>}>  $parallelGroups
     * @param  array<string, string>  $nodeOutputs
     */
    protected function finalizeQueuedHierarchicalResponse(
        SwarmExecutionState $state,
        HierarchicalRoutePlan $plan,
        Agent $coordinator,
        array $steps,
        array $mergedUsage,
        array $executedNodeIds,
        array $executedAgentClasses,
        array $parallelGroups,
        array $nodeOutputs,
        string $finalOutput,
    ): SwarmResponse {
        $executedSteps = count($executedAgentClasses) + 1;

        $state->context
            ->mergeData([
                'last_output' => $finalOutput,
                'steps' => $executedSteps,
                'hierarchical_node_outputs' => $nodeOutputs,
            ])
            ->mergeMetadata([
                'topology' => $state->topology->value,
                'coordinator_agent_class' => $coordinator::class,
                'route_plan_start' => $plan->startAt,
                'executed_node_ids' => $executedNodeIds,
                'executed_agent_classes' => $executedAgentClasses,
                'parallel_groups' => $parallelGroups,
                'executed_steps' => $executedSteps,
                'execution_mode' => $state->executionMode->value,
            ]);

        $state->contextStore->put($this->capture->activeContext($state->context), $state->ttlSeconds);

        return new SwarmResponse(
            output: $finalOutput,
            steps: $steps,
            usage: $mergedUsage,
            context: $state->context,
            artifacts: $state->context->artifacts,
            metadata: [
                'run_id' => $state->context->runId,
                'topology' => $state->topology->value,
                'coordinator_agent_class' => $coordinator::class,
                'route_plan_start' => $plan->startAt,
                'executed_node_ids' => $executedNodeIds,
                'executed_agent_classes' => $executedAgentClasses,
                'parallel_groups' => $parallelGroups,
                'executed_steps' => $executedSteps,
                'execution_mode' => $state->executionMode->value,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function runDurableStep(SwarmExecutionState $state, int $stepIndex, array $run): DurableHierarchicalStepResult
    {
        [$coordinator, $workers, $workerMap] = $this->resolveCoordinatorAndWorkers($state);

        if ($stepIndex === 0) {
            $coordinatorStep = $this->executeAgent(
                state: $state,
                agent: $coordinator,
                input: $state->context->input,
                index: 0,
                metadata: ['node_role' => 'coordinator'],
                storeContext: false,
                storeArtifacts: false,
            );

            $plan = $this->planner->fromCoordinatorOutput($coordinator, $workers, $coordinatorStep->output, $state->swarm::class);
            $this->ensurePlanWithinExecutionBudget($state, $plan);

            $cursor = $this->buildDurableCursor($plan, $coordinator::class);
            $nodeOutputs = [];
            $this->mergeDurableUsage($state, $coordinatorStep);
            $this->advanceDurableCursorToNextWorker($state, $plan, $cursor, $nodeOutputs);
            $this->applyDurableCursorToContext($state, $cursor);

            return new DurableHierarchicalStepResult(
                step: $coordinatorStep,
                routeCursor: $cursor,
                routePlan: $plan->toArray(),
                complete: $this->isDurableCursorComplete($cursor),
                totalSteps: (int) $cursor['total_steps'],
            );
        }

        $cursor = $this->durableCursor($state, $run);
        $plan = HierarchicalRoutePlan::fromArray($this->routePlan($state, $run));
        $entry = $cursor['entries'][$cursor['offset']] ?? null;

        if (! is_array($entry) || ($entry['type'] ?? null) !== 'worker') {
            if (is_array($entry) && ($entry['type'] ?? null) === 'parallel') {
                /** @var HierarchicalParallelNode $parallel */
                $parallel = $plan->node((string) $entry['node_id']);
                $branches = $this->durableRuns->branchesFor($state->context->runId, $parallel->id);

                if ($branches === []) {
                    $requiredNodeOutputIds = [];
                    foreach ($parallel->branches as $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $plan->node($branchNodeId);
                        $requiredNodeOutputIds = array_merge($requiredNodeOutputIds, array_values($branch->withOutputs));
                    }
                    $nodeOutputs = $this->durableRuns->hierarchicalNodeOutputsFor($state->context->runId, $requiredNodeOutputIds);
                    $branchDefinitions = [];

                    foreach ($parallel->branches as $ordinal => $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $plan->node($branchNodeId);
                        $input = $this->composePrompt($branch->prompt, $branch->withOutputs, $nodeOutputs, $branch->id);

                        $branchDefinitions[] = [
                            'branch_id' => $parallel->id.':'.$branch->id,
                            'step_index' => $stepIndex + $ordinal,
                            'node_id' => $branch->id,
                            'agent_class' => $branch->agentClass,
                            'parent_node_id' => $parallel->id,
                            'input' => $input,
                            'metadata' => array_merge($branch->metadata, [
                                'node_id' => $branch->id,
                                'parent_parallel_node_id' => $parallel->id,
                            ]),
                        ];
                    }

                    $cursor['current_node_id'] = $parallel->id;
                    $this->applyDurableCursorToContext($state, $cursor);

                    return new DurableHierarchicalStepResult(
                        step: null,
                        routeCursor: $cursor,
                        complete: false,
                        branches: $branchDefinitions,
                        waitingParentNodeId: $parallel->id,
                        nextStepIndex: $stepIndex + count($branchDefinitions),
                    );
                }

                if (! $this->branchesAreTerminal($branches)) {
                    $this->applyDurableCursorToContext($state, $cursor);

                    return new DurableHierarchicalStepResult(
                        step: null,
                        routeCursor: $cursor,
                        complete: false,
                        waitingParentNodeId: $parallel->id,
                    );
                }

                $nodeOutputs = $this->durableNodeOutputsForCursor($state, $plan, $cursor);
                foreach ($branches as $branch) {
                    if (($branch['status'] ?? null) === 'completed' && is_string($branch['node_id'] ?? null) && is_string($branch['output'] ?? null)) {
                        $nodeOutputs[$branch['node_id']] = $branch['output'];
                    }
                }

                $cursor['executed_node_ids'][] = $parallel->id;
                $cursor['completed_node_ids'][] = $parallel->id;
                $cursor['parallel_groups'][] = ['node_id' => $parallel->id, 'branches' => $parallel->branches];
                foreach ($branches as $branch) {
                    if (($branch['status'] ?? null) !== 'completed') {
                        continue;
                    }

                    if (is_string($branch['node_id'] ?? null)) {
                        $cursor['executed_node_ids'][] = $branch['node_id'];
                        $cursor['completed_node_ids'][] = $branch['node_id'];
                    }

                    if (is_string($branch['agent_class'] ?? null)) {
                        $cursor['executed_agent_classes'][] = $branch['agent_class'];
                    }
                }
                $cursor['offset'] += 1 + count($parallel->branches);

                $this->advanceDurableCursorToNextWorker($state, $plan, $cursor, $nodeOutputs);
                $this->applyDurableCursorToContext($state, $cursor);

                return new DurableHierarchicalStepResult(
                    step: null,
                    routeCursor: $cursor,
                    complete: $this->isDurableCursorComplete($cursor),
                    joinedBranches: true,
                );
            }

            $nodeOutputs = $this->durableNodeOutputsForCursor($state, $plan, $cursor);
            $this->advanceDurableCursorToNextWorker($state, $plan, $cursor, $nodeOutputs);
            $this->applyDurableCursorToContext($state, $cursor);

            return new DurableHierarchicalStepResult(
                step: null,
                routeCursor: $cursor,
                complete: $this->isDurableCursorComplete($cursor),
            );
        }

        /** @var HierarchicalWorkerNode $node */
        $node = $plan->node((string) $entry['node_id']);
        $nodeOutputs = $this->durableNodeOutputsForCursor($state, $plan, $cursor, $node);
        $parentParallelNodeId = $entry['parent_parallel_node_id'] ?? null;
        $metadata = array_merge($node->metadata, ['node_id' => $node->id]);

        if (is_string($parentParallelNodeId)) {
            $metadata['parent_parallel_node_id'] = $parentParallelNodeId;
        }

        $step = $this->executeAgent(
            state: $state,
            agent: $workerMap[$node->agentClass],
            input: $this->composePrompt($node->prompt, $node->withOutputs, $nodeOutputs, $node->id),
            index: $stepIndex,
            metadata: $metadata,
            storeContext: false,
            storeArtifacts: false,
        );

        $nodeOutputs[$node->id] = $step->output;
        $this->mergeDurableUsage($state, $step);
        $cursor['executed_node_ids'][] = $node->id;
        $cursor['completed_node_ids'][] = $node->id;
        $cursor['executed_agent_classes'][] = $step->agentClass;
        $cursor['offset']++;

        $this->advanceDurableCursorToNextWorker($state, $plan, $cursor, $nodeOutputs);
        $this->applyDurableCursorToContext($state, $cursor);

        return new DurableHierarchicalStepResult(
            step: $step,
            routeCursor: $cursor,
            nodeOutput: ['node_id' => $node->id, 'output' => $step->output],
            complete: $this->isDurableCursorComplete($cursor),
        );
    }

    /**
     * @param  array<class-string, Agent>  $workerMap
     * @param  array<int, SwarmStep>  $steps
     * @param  array<string, int>  $mergedUsage
     * @param  array<int, string>  $executedNodeIds
     * @param  array<int, string>  $executedAgentClasses
     * @param  array<int, array{node_id: string, branches: array<int, string>}>  $parallelGroups
     * @param  array<string, string>  $nodeOutputs
     */
    protected function executePlan(
        SwarmExecutionState $state,
        HierarchicalRoutePlan $plan,
        string $coordinatorClass,
        array $workerMap,
        array &$steps,
        array &$mergedUsage,
        array &$executedNodeIds,
        array &$executedAgentClasses,
        array &$parallelGroups,
        array &$nodeOutputs,
        int &$nextIndex,
        ?QueueHierarchicalParallelBoundary &$deferral = null,
        ?string $startNodeId = null,
    ): string {
        $currentNodeId = $startNodeId ?? $plan->startAt;
        $lastOutput = null;

        while ($currentNodeId !== null) {
            $node = $plan->node($currentNodeId);

            $executedNodeIds[] = $node->id;

            if ($node instanceof HierarchicalWorkerNode) {
                $step = $this->executeAgent(
                    state: $state,
                    agent: $workerMap[$node->agentClass],
                    input: $this->composePrompt($node->prompt, $node->withOutputs, $nodeOutputs, $node->id),
                    index: $nextIndex,
                    metadata: array_merge($node->metadata, ['node_id' => $node->id]),
                );

                $steps[] = $step;
                $mergedUsage = $this->mergeUsage($mergedUsage, (array) ($step->metadata['usage'] ?? []));
                $nodeOutputs[$node->id] = $step->output;
                $executedAgentClasses[] = $step->agentClass;
                $lastOutput = $step->output;
                $nextIndex++;
                $currentNodeId = $node->next;

                continue;
            }

            if ($node instanceof HierarchicalParallelNode) {
                $parallelGroups[] = ['node_id' => $node->id, 'branches' => $node->branches];

                if ($state->executionMode === ExecutionMode::Queue && $state->queueHierarchicalParallelCoordination === 'multi_worker') {
                    $routeCursor = $this->cursorAtQueueParallelBoundary($state, $plan, $coordinatorClass, $node, $nodeOutputs);
                    $branchDefinitions = [];

                    foreach ($node->branches as $ordinal => $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $plan->node($branchNodeId);
                        $input = $this->composePrompt($branch->prompt, $branch->withOutputs, $nodeOutputs, $branch->id);
                        $branchDefinitions[] = [
                            'branch_id' => $node->id.':'.$branch->id,
                            'step_index' => $nextIndex + $ordinal,
                            'node_id' => $branch->id,
                            'agent_class' => $branch->agentClass,
                            'parent_node_id' => $node->id,
                            'input' => $input,
                            'metadata' => array_merge($branch->metadata, [
                                'node_id' => $branch->id,
                                'parent_parallel_node_id' => $node->id,
                            ]),
                        ];
                    }

                    $deferral = new QueueHierarchicalParallelBoundary(
                        parentParallelNodeId: $node->id,
                        branchDefinitions: $branchDefinitions,
                        routeCursor: $routeCursor,
                        routePlan: $plan->toArray(),
                        nextStepIndexAfterJoin: $nextIndex + count($node->branches),
                        totalSteps: (int) $routeCursor['total_steps'],
                        stepsSoFar: array_values($steps),
                        mergedUsage: $mergedUsage,
                        executedNodeIds: $executedNodeIds,
                        executedAgentClasses: $executedAgentClasses,
                        parallelGroups: $parallelGroups,
                        nodeOutputs: $nodeOutputs,
                        coordinatorClass: $coordinatorClass,
                    );

                    return '';
                }

                if ($state->executionMode === ExecutionMode::Queue) {
                    foreach ($node->branches as $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $plan->node($branchNodeId);
                        $step = $this->executeAgent(
                            state: $state,
                            agent: $workerMap[$branch->agentClass],
                            input: $this->composePrompt($branch->prompt, $branch->withOutputs, $nodeOutputs, $branch->id),
                            index: $nextIndex,
                            metadata: array_merge($branch->metadata, [
                                'node_id' => $branch->id,
                                'parent_parallel_node_id' => $node->id,
                            ]),
                        );

                        $steps[] = $step;
                        $mergedUsage = $this->mergeUsage($mergedUsage, (array) ($step->metadata['usage'] ?? []));
                        $nodeOutputs[$branch->id] = $step->output;
                        $executedNodeIds[] = $branch->id;
                        $executedAgentClasses[] = $step->agentClass;
                        $lastOutput = $step->output;
                        $nextIndex++;
                    }
                } else {
                    $branchDefinitions = [];
                    $callbacks = [];

                    foreach ($node->branches as $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $plan->node($branchNodeId);
                        $workerClass = $branch->agentClass;
                        if (! is_subclass_of($workerClass, Agent::class)) {
                            throw new SwarmException("Hierarchical parallel worker [{$workerClass}] must be a Laravel AI agent class.");
                        }
                        $this->resolveParallelWorker($state->swarm::class, $workerClass);
                        $input = $this->composePrompt($branch->prompt, $branch->withOutputs, $nodeOutputs, $branch->id);

                        $this->stepsRecorder->started($state, $nextIndex + count($branchDefinitions), $branch->agentClass, $input);

                        $branchDefinitions[$branchNodeId] = [
                            'node' => $branch,
                            'input' => $input,
                            'index' => $nextIndex + count($branchDefinitions),
                        ];

                        $agentClass = $branch->agentClass;
                        $callbacks[$branchNodeId] = function () use ($agentClass, $input): array {
                            $worker = Container::getInstance()->make($agentClass);

                            if (! $worker instanceof Agent) {
                                throw new SwarmException("Hierarchical parallel worker [{$agentClass}] must resolve to a Laravel AI agent.");
                            }

                            $startedAt = MonotonicTime::now();
                            $response = $worker->prompt($input);

                            return [
                                'output' => (string) $response,
                                'usage' => $response->usage->toArray(),
                                'duration_ms' => MonotonicTime::elapsedMilliseconds($startedAt),
                            ];
                        };
                    }

                    /** @var array<string, array{output: string, usage: array<string, int>, duration_ms: int}> $results */
                    $results = $this->concurrency->driver()->run($callbacks);

                    foreach ($node->branches as $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $branchDefinitions[$branchNodeId]['node'];
                        $input = $branchDefinitions[$branchNodeId]['input'];
                        $index = $branchDefinitions[$branchNodeId]['index'];

                        if (! array_key_exists($branchNodeId, $results)) {
                            throw new SwarmException($state->swarm::class.": hierarchical parallel execution did not return a result for branch node [{$branchNodeId}].");
                        }

                        $row = $results[$branchNodeId];

                        $step = $this->stepsRecorder->completed(
                            state: $state,
                            index: $index,
                            agentClass: $branch->agentClass,
                            input: $input,
                            output: $row['output'],
                            usage: $row['usage'],
                            durationMs: $row['duration_ms'],
                            metadata: array_merge($branch->metadata, [
                                'index' => $index,
                                'usage' => $row['usage'],
                                'node_id' => $branch->id,
                                'parent_parallel_node_id' => $node->id,
                            ]),
                            updateContext: false,
                            storeContext: false,
                            includeUsageInMetadata: false,
                        );

                        $steps[] = $step;
                        $mergedUsage = $this->mergeUsage($mergedUsage, $row['usage']);
                        $nodeOutputs[$branch->id] = $step->output;
                        $executedNodeIds[] = $branch->id;
                        $executedAgentClasses[] = $step->agentClass;
                        $lastOutput = $step->output;
                    }

                    $state->contextStore->put($this->capture->activeContext($state->context), $state->ttlSeconds);
                    $nextIndex += count($node->branches);
                }

                $currentNodeId = $node->next;

                continue;
            }

            /** @var HierarchicalFinishNode $node */
            return $node->output ?? $this->resolveOutputFromNode($node, $nodeOutputs);
        }

        return $lastOutput ?? '';
    }

    protected function ensurePlanWithinExecutionBudget(SwarmExecutionState $state, HierarchicalRoutePlan $plan): void
    {
        $requiredExecutions = 1 + $plan->reachableWorkerCount();

        if ($requiredExecutions <= $state->maxAgentExecutions) {
            return;
        }

        throw new SwarmException(sprintf(
            "%s: hierarchical route plan requires %d agent executions but the swarm allows %d. Increase #[MaxAgentSteps] or reduce the plan's worker nodes.",
            $state->swarm::class,
            $requiredExecutions,
            $state->maxAgentExecutions,
        ));
    }

    /**
     * @return array{0: Agent, 1: array<int, Agent>, 2: array<class-string, Agent>}
     */
    protected function resolveCoordinatorAndWorkers(SwarmExecutionState $state): array
    {
        $agents = $state->swarm->agents();

        if ($agents === []) {
            throw new SwarmException('Hierarchical swarms must define at least one agent.');
        }

        /** @var Agent $coordinator */
        $coordinator = array_shift($agents);
        $this->planner->assertCoordinatorCanPlan($coordinator);
        $this->ensureUniqueWorkerClasses($state->swarm::class, $agents);

        return [$coordinator, $agents, $this->workerMap($agents)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDurableCursor(HierarchicalRoutePlan $plan, string $coordinatorClass): array
    {
        $entries = $this->durableEntries($plan);

        return [
            'entries' => $entries,
            'offset' => 0,
            'current_node_id' => null,
            'completed_node_ids' => [],
            'executed_node_ids' => [],
            'executed_agent_classes' => [],
            'parallel_groups' => [],
            'final_output' => null,
            'coordinator_agent_class' => $coordinatorClass,
            'route_plan_start' => $plan->startAt,
            'total_steps' => 1 + count(array_filter($entries, static fn (array $entry): bool => $entry['type'] === 'worker')),
        ];
    }

    /**
     * @return array<int, array{type: string, node_id: string, parent_parallel_node_id?: string}>
     */
    protected function durableEntries(HierarchicalRoutePlan $plan): array
    {
        $entries = [];
        $this->appendDurableEntries($plan, $plan->startAt, $entries);

        return $entries;
    }

    /**
     * @param  array<int, array{type: string, node_id: string, parent_parallel_node_id?: string}>  $entries
     */
    protected function appendDurableEntries(HierarchicalRoutePlan $plan, string $nodeId, array &$entries): void
    {
        $node = $plan->node($nodeId);

        if ($node instanceof HierarchicalWorkerNode) {
            $entries[] = ['type' => 'worker', 'node_id' => $node->id];

            if ($node->next !== null) {
                $this->appendDurableEntries($plan, $node->next, $entries);
            }

            return;
        }

        if ($node instanceof HierarchicalParallelNode) {
            $entries[] = ['type' => 'parallel', 'node_id' => $node->id];

            foreach ($node->branches as $branchNodeId) {
                $entries[] = [
                    'type' => 'worker',
                    'node_id' => $branchNodeId,
                    'parent_parallel_node_id' => $node->id,
                ];
            }

            if ($node->next !== null) {
                $this->appendDurableEntries($plan, $node->next, $entries);
            }

            return;
        }

        $entries[] = ['type' => 'finish', 'node_id' => $node->id];
    }

    /**
     * @param  array<string, mixed>  $cursor
     * @param  array<string, string>  $nodeOutputs
     */
    protected function advanceDurableCursorToNextWorker(SwarmExecutionState $state, HierarchicalRoutePlan $plan, array &$cursor, array $nodeOutputs): void
    {
        while (isset($cursor['entries'][$cursor['offset']])) {
            $entry = $cursor['entries'][$cursor['offset']];

            if (in_array($entry['type'] ?? null, ['worker', 'parallel'], true)) {
                $cursor['current_node_id'] = $entry['node_id'];

                return;
            }

            $node = $plan->node((string) $entry['node_id']);
            $cursor['executed_node_ids'][] = $node->id;
            $cursor['completed_node_ids'][] = $node->id;

            if ($node instanceof HierarchicalParallelNode) {
                $cursor['parallel_groups'][] = ['node_id' => $node->id, 'branches' => $node->branches];
            }

            if ($node instanceof HierarchicalFinishNode) {
                $cursor['final_output'] = $node->output ?? $this->resolveOutputFromNode($node, $nodeOutputs);
                $state->context->mergeData(['last_output' => $cursor['final_output']]);
            }

            $cursor['offset']++;
        }

        $cursor['current_node_id'] = null;
    }

    /**
     * @param  array<string, mixed>  $cursor
     * @return array<string, string>
     */
    protected function durableNodeOutputsForCursor(SwarmExecutionState $state, HierarchicalRoutePlan $plan, array $cursor, ?HierarchicalWorkerNode $worker = null): array
    {
        $nodeIds = $worker !== null ? array_values($worker->withOutputs) : [];
        $offset = (int) ($cursor['offset'] ?? 0);

        if ($worker !== null) {
            $offset++;
        }

        while (isset($cursor['entries'][$offset])) {
            $entry = $cursor['entries'][$offset];

            if (($entry['type'] ?? null) === 'worker') {
                break;
            }

            $node = $plan->node((string) $entry['node_id']);

            if ($node instanceof HierarchicalFinishNode && $node->outputFrom !== null) {
                $nodeIds[] = $node->outputFrom;
            }

            $offset++;
        }

        return $this->durableRuns->hierarchicalNodeOutputsFor($state->context->runId, $nodeIds);
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    protected function durableCursor(SwarmExecutionState $state, array $run): array
    {
        $cursor = $run['route_cursor'] ?? null;

        if (! is_array($cursor)) {
            throw new SwarmException("Durable hierarchical run [{$state->context->runId}] is missing its persisted route cursor.");
        }

        return $cursor;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    protected function routePlan(SwarmExecutionState $state, array $run): array
    {
        $plan = $run['route_plan'] ?? null;

        if (! is_array($plan)) {
            throw new SwarmException("Durable hierarchical run [{$state->context->runId}] is missing its persisted route plan.");
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $cursor
     */
    protected function applyDurableCursorToContext(SwarmExecutionState $state, array $cursor): void
    {
        $state->context
            ->mergeMetadata([
                'topology' => $state->topology->value,
                'coordinator_agent_class' => $cursor['coordinator_agent_class'],
                'route_plan_start' => $cursor['route_plan_start'],
                'current_node_id' => $cursor['current_node_id'],
                'completed_node_ids' => $cursor['completed_node_ids'],
                'executed_node_ids' => $cursor['executed_node_ids'],
                'executed_agent_classes' => $cursor['executed_agent_classes'],
                'parallel_groups' => $cursor['parallel_groups'],
                'executed_steps' => count($cursor['executed_agent_classes']) + 1,
                'total_steps' => $cursor['total_steps'],
                'execution_mode' => $state->executionMode->value,
            ]);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function durableCursorComplete(SwarmExecutionState $state, array $run): bool
    {
        $cursor = $run['route_cursor'] ?? null;

        return is_array($cursor)
            && ($cursor['current_node_id'] ?? null) === null
            && isset($cursor['entries'], $cursor['offset'])
            && (int) $cursor['offset'] >= count($cursor['entries']);
    }

    /**
     * @param  array<string, mixed>  $cursor
     */
    protected function isDurableCursorComplete(array $cursor): bool
    {
        return ($cursor['current_node_id'] ?? null) === null
            && isset($cursor['entries'], $cursor['offset'])
            && (int) $cursor['offset'] >= count($cursor['entries']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     */
    protected function branchesAreTerminal(array $branches): bool
    {
        foreach ($branches as $branch) {
            if (! in_array($branch['status'] ?? null, ['completed', 'failed', 'cancelled'], true)) {
                return false;
            }
        }

        return $branches !== [];
    }

    protected function mergeDurableUsage(SwarmExecutionState $state, SwarmStep $step): void
    {
        $state->context->mergeMetadata([
            'usage' => $this->mergeUsage(
                is_array($state->context->metadata['usage'] ?? null) ? $state->context->metadata['usage'] : [],
                is_array($step->metadata['usage'] ?? null) ? $step->metadata['usage'] : [],
            ),
        ]);
    }

    /**
     * @param  array<string, string>  $nodeOutputs
     */
    protected function resolveOutputFromNode(HierarchicalFinishNode $node, array $nodeOutputs): string
    {
        $sourceNodeId = $node->outputFrom;

        if ($sourceNodeId === null) {
            throw new SwarmException("Hierarchical finish node [{$node->id}] did not resolve a final output.");
        }

        if (! array_key_exists($sourceNodeId, $nodeOutputs)) {
            throw new SwarmException("Hierarchical finish node [{$node->id}] cannot resolve output from unexecuted node [{$sourceNodeId}].");
        }

        return $nodeOutputs[$sourceNodeId];
    }

    /**
     * @param  array<string, string>  $withOutputs
     * @param  array<string, string>  $nodeOutputs
     */
    protected function composePrompt(string $prompt, array $withOutputs, array $nodeOutputs, string $nodeId): string
    {
        if ($withOutputs === []) {
            return $prompt;
        }

        $sections = [];

        foreach ($withOutputs as $alias => $sourceNodeId) {
            if (! array_key_exists($sourceNodeId, $nodeOutputs)) {
                throw new SwarmException("Hierarchical worker node [{$nodeId}] cannot resolve named output [{$alias}] from unexecuted node [{$sourceNodeId}].");
            }

            $sections[] = "[{$alias}]\n".$nodeOutputs[$sourceNodeId];
        }

        return rtrim($prompt)."\n\nNamed outputs:\n".implode("\n\n", $sections);
    }

    /**
     * @param  array<int, Agent>  $workers
     * @return array<class-string, Agent>
     */
    protected function workerMap(array $workers): array
    {
        $map = [];

        foreach ($workers as $worker) {
            $map[$worker::class] = $worker;
        }

        return $map;
    }

    public function ensureUniqueWorkerClassesForSwarm(Swarm $swarm): void
    {
        $agents = $swarm->agents();

        if ($agents === []) {
            return;
        }

        array_shift($agents);
        $this->ensureUniqueWorkerClasses($swarm::class, $agents);
    }

    /**
     * @param  array<int, Agent>  $workers
     */
    protected function ensureUniqueWorkerClasses(string $swarmClass, array $workers): void
    {
        $seen = [];

        foreach ($workers as $worker) {
            if (isset($seen[$worker::class])) {
                throw new SwarmException($swarmClass.': agents() contains duplicate agent class '.$worker::class.'. Hierarchical worker classes must be unique.');
            }

            $seen[$worker::class] = true;
        }
    }

    /**
     * @param  class-string<Agent>  $agentClass
     */
    protected function resolveParallelWorker(string $swarmClass, string $agentClass): Agent
    {
        try {
            $worker = Container::getInstance()->make($agentClass);
        } catch (BindingResolutionException $exception) {
            throw new SwarmException(
                "{$swarmClass}: hierarchical parallel worker [{$agentClass}] must be container-resolvable because Laravel Concurrency serializes worker callbacks.",
                previous: $exception,
            );
        }

        if (! $worker instanceof Agent) {
            throw new SwarmException("{$swarmClass}: hierarchical parallel worker [{$agentClass}] must resolve to a Laravel AI agent.");
        }

        return $worker;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function executeAgent(SwarmExecutionState $state, Agent $agent, string $input, int $index, array $metadata = [], bool $storeContext = true, bool $storeArtifacts = true): SwarmStep
    {
        if (hrtime(true) >= $state->deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout while running hierarchically.');
        }

        $this->stepsRecorder->started($state, $index, $agent::class, $input);

        $startedAt = MonotonicTime::now();
        $response = $agent->prompt($input);
        $output = (string) $response;
        $usage = $this->usageFromResponse($response);

        return $this->stepsRecorder->completed(
            state: $state,
            index: $index,
            agentClass: $agent::class,
            input: $input,
            output: $output,
            usage: $usage,
            durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
            metadata: $metadata,
            storeContext: $storeContext,
            storeArtifacts: $storeArtifacts,
        );
    }
}
