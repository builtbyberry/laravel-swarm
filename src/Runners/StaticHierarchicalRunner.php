<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;

/**
 * @internal
 */
class StaticHierarchicalRunner extends HierarchicalRunner
{
    public function run(SwarmExecutionState $state): SwarmResponse
    {
        $swarm = $state->swarm;

        if (! $swarm instanceof HasRoutePlan) {
            throw new SwarmException(
                $swarm::class.': static hierarchical swarms must implement HasRoutePlan and define a plan() method.'
            );
        }

        $agents = $swarm->agents();

        if ($agents === []) {
            throw new SwarmException('Static hierarchical swarms must define at least one agent.');
        }

        $this->ensureUniqueWorkerClasses($swarm::class, $agents);
        $workerMap = $this->workerMap($agents);

        $plan = $this->planner->fromStaticPlan($agents, $swarm->plan(), $swarm::class);
        $this->ensureStaticPlanWithinExecutionBudget($state, $plan);

        $steps = [];
        $mergedUsage = [];
        $executedNodeIds = [];
        $executedAgentClasses = [];
        $parallelGroups = [];
        $nodeOutputs = [];
        $nextIndex = 0; // no coordinator step consumes slot 0
        $deferral = null;

        $finalOutput = $this->executePlan(
            $state,
            $plan,
            '',        // no coordinator class; queue multi-worker path is unreachable (queueHierarchicalParallelCoordination is null)
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
                // coordinator_agent_class intentionally absent
                'route_plan_start' => $plan->startAt,
                'executed_node_ids' => $executedNodeIds,
                'executed_agent_classes' => $executedAgentClasses,
                'parallel_groups' => $parallelGroups,
                'executed_steps' => count($steps),
                'execution_mode' => $state->executionMode->value,
            ],
        );
    }

    public function buildPlanForSwarm(Swarm $swarm): HierarchicalRoutePlan
    {
        assert($swarm instanceof HasRoutePlan);

        return $this->planner->fromStaticPlan($swarm->agents(), $swarm->plan(), $swarm::class);
    }

    public function runDurableStep(SwarmExecutionState $state, int $stepIndex, array $run): DurableHierarchicalStepResult
    {
        if ($stepIndex === 0) {
            return $this->runStaticDurableInitStep($state);
        }

        $agents = $state->swarm->agents();

        return $this->runDurableWorkerStep($state, $stepIndex, $run, $this->workerMap($agents));
    }

    private function runStaticDurableInitStep(SwarmExecutionState $state): DurableHierarchicalStepResult
    {
        assert($state->swarm instanceof HasRoutePlan);

        $agents = $state->swarm->agents();
        $this->ensureUniqueWorkerClasses($state->swarm::class, $agents);
        $plan = $this->planner->fromStaticPlan($agents, $state->swarm->plan(), $state->swarm::class);
        $this->ensureStaticPlanWithinExecutionBudget($state, $plan);
        $cursor = $this->buildStaticDurableCursor($plan);
        $nodeOutputs = [];
        $this->advanceDurableCursorToNextWorker($state, $plan, $cursor, $nodeOutputs);
        $this->applyDurableCursorToContext($state, $cursor);

        return new DurableHierarchicalStepResult(
            step: null,
            routeCursor: $cursor,
            routePlan: $plan->toArray(),
            complete: $this->isDurableCursorComplete($cursor),
            totalSteps: (int) $cursor['total_steps'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildStaticDurableCursor(HierarchicalRoutePlan $plan): array
    {
        $entries = $this->durableEntries($plan);

        return [
            'entries'                 => $entries,
            'offset'                  => 0,
            'current_node_id'         => null,
            'completed_node_ids'      => [],
            'executed_node_ids'       => [],
            'executed_agent_classes'  => [],
            'parallel_groups'         => [],
            'final_output'            => null,
            'coordinator_agent_class' => '',
            'route_plan_start'        => $plan->startAt,
            'total_steps'             => count(array_filter(
                $entries,
                static fn (array $e): bool => $e['type'] === 'worker',
            )),
        ];
    }

    protected function ensureStaticPlanWithinExecutionBudget(SwarmExecutionState $state, HierarchicalRoutePlan $plan): void
    {
        $required = $plan->reachableWorkerCount();

        if ($required <= $state->maxAgentExecutions) {
            return;
        }

        throw new SwarmException(sprintf(
            '%s: static route plan requires %d agent executions but the swarm allows %d. '
            ."Increase #[MaxAgentSteps] or reduce the plan's worker nodes.",
            $state->swarm::class,
            $required,
            $state->maxAgentExecutions,
        ));
    }
}
