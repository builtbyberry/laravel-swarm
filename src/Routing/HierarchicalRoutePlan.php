<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

class HierarchicalRoutePlan
{
    /**
     * @param  array<string, HierarchicalRouteNode>  $nodes
     */
    public function __construct(
        public readonly string $startAt,
        public readonly array $nodes,
    ) {}

    public function node(string $nodeId): HierarchicalRouteNode
    {
        return $this->nodes[$nodeId];
    }

    public function reachableWorkerCount(): int
    {
        $visited = [];

        return $this->countReachableWorkers($this->startAt, $visited);
    }

    /**
     * @param  array<string, true>  $visited
     */
    protected function countReachableWorkers(string $nodeId, array &$visited): int
    {
        if (isset($visited[$nodeId])) {
            return 0;
        }

        $visited[$nodeId] = true;
        $node = $this->node($nodeId);
        $count = $node instanceof HierarchicalWorkerNode ? 1 : 0;

        foreach ($node->controlEdges() as $nextNodeId) {
            $count += $this->countReachableWorkers($nextNodeId, $visited);
        }

        return $count;
    }
}
