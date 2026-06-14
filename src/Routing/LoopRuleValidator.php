<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

/**
 * Single source of truth for the rule-bearing loop and acyclicity invariants
 * that must hold identically whether a hierarchical route plan is freshly built
 * from a coordinator/static plan or rehydrated from persisted durable state.
 *
 * The build and rehydrate paths historically duplicated these rules, which let
 * the two surfaces drift. Hoisting them here keeps them in lockstep; the only
 * intended difference between surfaces is the diagnostic message prefix.
 *
 * @internal
 */
class LoopRuleValidator
{
    /**
     * Validate every bounded loop back-edge in the plan.
     *
     * @param  string  $prefix  The message prefix that distinguishes the surface,
     *                          e.g. "Hierarchical" (build) or "Persisted hierarchical"
     *                          (rehydrate). Preserves existing assertion text exactly.
     */
    public function assertLoops(HierarchicalRoutePlan $plan, string $prefix): void
    {
        $branchNodeIds = [];

        foreach ($plan->nodes as $node) {
            if ($node instanceof HierarchicalParallelNode) {
                foreach ($node->branches as $branchNodeId) {
                    $branchNodeIds[$branchNodeId] = true;
                }
            }
        }

        foreach ($plan->nodes as $node) {
            if (! $node instanceof HierarchicalWorkerNode || ! $node->hasLoop()) {
                continue;
            }

            if (isset($branchNodeIds[$node->id])) {
                throw new SwarmException("{$prefix} worker node [{$node->id}] cannot define a [loop] while used as a parallel branch.");
            }

            $target = $plan->node((string) $node->loopTo);

            if (! $target instanceof HierarchicalWorkerNode) {
                throw new SwarmException("{$prefix} worker node [{$node->id}] may only loop back to a worker node, not [{$node->loopTo}].");
            }

            if (! $this->controlReaches((string) $node->loopTo, $node->id, $plan)) {
                throw new SwarmException("{$prefix} worker node [{$node->id}] must loop back to an earlier node on its own path; [{$node->loopTo}] does not reach [{$node->id}].");
            }

            $this->assertNestingWellFormed($plan, $node, $prefix);
        }
    }

    /**
     * Nested loops must nest cleanly: when an outer loop body contains an inner
     * looping worker, the inner loop's body must be fully contained within the
     * outer loop's body. A back-edge that straddles another loop's boundary
     * cannot be reset deterministically on outer re-entry, so it is rejected at
     * plan time rather than silently mis-counting at runtime.
     */
    protected function assertNestingWellFormed(HierarchicalRoutePlan $plan, HierarchicalWorkerNode $node, string $prefix): void
    {
        $outerBody = $this->loopBodyNodeIds($plan, (string) $node->loopTo, $node->id);

        foreach ($plan->nodes as $other) {
            if ($other === $node || ! $other instanceof HierarchicalWorkerNode || ! $other->hasLoop()) {
                continue;
            }

            // Only consider loops whose looping node sits strictly inside this body.
            if ($other->id === $node->id || ! isset($outerBody[$other->id])) {
                continue;
            }

            // The inner loop's target must also live inside the outer body, so the
            // inner back-edge is fully contained and can be reset on outer re-entry.
            if (! isset($outerBody[(string) $other->loopTo])) {
                throw new SwarmException("{$prefix} worker node [{$other->id}] loops back to [{$other->loopTo}], which escapes the enclosing loop of [{$node->id}]; nested loops must be fully contained.");
            }
        }
    }

    /**
     * The set of node ids on the forward control path from $loopTo to
     * $loopNodeId (inclusive), excluding loop back-edges. Uses a visited-set so
     * shared join nodes are walked once.
     *
     * @return array<string, true>
     */
    protected function loopBodyNodeIds(HierarchicalRoutePlan $plan, string $loopTo, string $loopNodeId): array
    {
        $body = [];
        $seen = [];
        $stack = [$loopTo];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $body[$current] = true;

            if ($current === $loopNodeId) {
                continue;
            }

            foreach ($plan->node($current)->controlEdges() as $edge) {
                $stack[] = $edge;
            }
        }

        return $body;
    }

    /**
     * Whether $targetNodeId is reachable from $fromNodeId following forward
     * control edges only (loop back-edges excluded). Used to confirm a loop
     * back-edge points to a genuine ancestor on the same path.
     */
    public function controlReaches(string $fromNodeId, string $targetNodeId, HierarchicalRoutePlan $plan): bool
    {
        $seen = [];
        $stack = [$fromNodeId];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === $targetNodeId) {
                return true;
            }

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            foreach ($plan->node($current)->controlEdges() as $edge) {
                $stack[] = $edge;
            }
        }

        return false;
    }

    /**
     * Assert the plan is acyclic modulo bounded loop back-edges. controlEdges()
     * deliberately excludes loop edges, so a bounded loop does not register as a
     * cycle here — only genuine unbounded cycles do.
     *
     * @param  string  $message  The exact exception message to throw on a cycle.
     *                           Differs by surface in both prefix and suffix, so it
     *                           is passed in full rather than reconstructed.
     */
    public function assertAcyclic(HierarchicalRoutePlan $plan, string $message): void
    {
        $visited = [];
        $inProgress = [];

        if ($this->hasCycle($plan->startAt, $plan, $visited, $inProgress)) {
            throw new SwarmException($message);
        }
    }

    /**
     * @param  array<string, bool>  $visited
     * @param  array<string, bool>  $inProgress
     */
    protected function hasCycle(string $nodeId, HierarchicalRoutePlan $plan, array &$visited, array &$inProgress): bool
    {
        $visited[$nodeId] = true;
        $inProgress[$nodeId] = true;

        foreach ($plan->node($nodeId)->controlEdges() as $nextNodeId) {
            if (! isset($visited[$nextNodeId])) {
                if ($this->hasCycle($nextNodeId, $plan, $visited, $inProgress)) {
                    return true;
                }
            } elseif (isset($inProgress[$nextNodeId])) {
                return true;
            }
        }

        unset($inProgress[$nodeId]);

        return false;
    }
}
