<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;

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
