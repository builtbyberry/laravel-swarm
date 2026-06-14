<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Concerns;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalFinishNode;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalParallelNode;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalWorkerNode;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;

/**
 * @internal
 */
trait AlignsQueuedHierarchicalParallelCursor
{
    /**
     * Build a durable-style route cursor positioned at a parallel node before branch rows exist.
     *
     * @param  array<string, string>  $nodeOutputs
     * @param  array<string, int>  $loopIterations  Per-node loop counters carried from the live
     *                                              in-process walk so a parallel fan-out reached on
     *                                              a later loop pass persists its real iteration —
     *                                              without this the resumed cursor restarts the loop
     *                                              from iteration 1 and the run never converges.
     * @return array<string, mixed>
     */
    protected function cursorAtQueueParallelBoundary(
        SwarmExecutionState $state,
        HierarchicalRoutePlan $plan,
        string $coordinatorClass,
        HierarchicalParallelNode $parallel,
        array $nodeOutputs,
        array $loopIterations = [],
    ): array {
        $cursor = $this->buildDurableCursor($plan, $coordinatorClass);
        $cursor['loop_iterations'] = $loopIterations;

        while (isset($cursor['entries'][$cursor['offset']])) {
            $entry = $cursor['entries'][$cursor['offset']];

            if (($entry['type'] ?? null) === 'parallel' && ($entry['node_id'] ?? null) === $parallel->id) {
                $cursor['current_node_id'] = $parallel->id;

                return $cursor;
            }

            if (($entry['type'] ?? null) === 'worker') {
                $workerId = (string) ($entry['node_id'] ?? '');
                $cursor['executed_node_ids'][] = $workerId;
                $cursor['completed_node_ids'][] = $workerId;
                $workerNode = $plan->node($workerId);

                if ($workerNode instanceof HierarchicalWorkerNode) {
                    $cursor['executed_agent_classes'][] = $workerNode->agentClass;
                }

                $cursor['offset']++;

                continue;
            }

            if (($entry['type'] ?? null) === 'finish') {
                $finishNode = $plan->node((string) ($entry['node_id'] ?? ''));
                $cursor['executed_node_ids'][] = $finishNode->id;
                $cursor['completed_node_ids'][] = $finishNode->id;

                if ($finishNode instanceof HierarchicalFinishNode) {
                    $cursor['final_output'] = $finishNode->output ?? $this->resolveOutputFromNode($finishNode, $nodeOutputs);
                    $state->context->mergeData(['last_output' => $cursor['final_output']]);
                }

                $cursor['offset']++;

                continue;
            }

            $cursor['offset']++;
        }

        throw new SwarmException('Hierarchical route plan for swarm ['.$state->swarm::class.'] did not reach parallel node ['.$parallel->id.'] while aligning coordination cursor.');
    }
}
