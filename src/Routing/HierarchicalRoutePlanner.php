<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use JsonException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;

class HierarchicalRoutePlanner
{
    /**
     * @param  array<int, Agent>  $workers
     */
    public function fromCoordinatorOutput(Agent $coordinator, array $workers, string $payload, string $swarmClass): HierarchicalRoutePlan
    {
        $this->assertCoordinatorCanPlan($coordinator);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SwarmException('Hierarchical coordinator output must be valid JSON matching the declared route-plan schema.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new SwarmException('Hierarchical coordinator output must decode to a route-plan object.');
        }

        return $this->normalizeAndValidate($coordinator, $workers, $decoded, $swarmClass);
    }

    public function assertCoordinatorCanPlan(Agent $coordinator): void
    {
        if (! $coordinator instanceof HasStructuredOutput) {
            throw new SwarmException('Hierarchical coordinators must implement Laravel AI structured output.');
        }
    }

    /**
     * @param  array<int, Agent>  $workers
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeAndValidate(Agent $coordinator, array $workers, array $payload, string $swarmClass): HierarchicalRoutePlan
    {
        $startAt = $payload['start_at'] ?? null;
        $nodesPayload = $payload['nodes'] ?? null;

        if (! is_string($startAt) || $startAt === '') {
            throw new SwarmException('Hierarchical route plans must define a non-empty [start_at] node id.');
        }

        if (! is_array($nodesPayload) || array_is_list($nodesPayload)) {
            throw new SwarmException('Hierarchical route plans must define [nodes] as an object keyed by node id.');
        }

        $workerClasses = array_map(static fn (Agent $agent): string => $agent::class, $workers);
        $nodes = [];

        foreach ($nodesPayload as $nodeId => $nodePayload) {
            if (! is_string($nodeId) || $nodeId === '') {
                throw new SwarmException('Hierarchical route plan node ids must be non-empty strings.');
            }

            if (! is_array($nodePayload)) {
                throw new SwarmException("Hierarchical route node [{$nodeId}] must be an object.");
            }

            $nodes[$nodeId] = $this->normalizeNode($nodeId, $nodePayload, $coordinator::class, $workerClasses, $swarmClass);
        }

        if (! array_key_exists($startAt, $nodes)) {
            throw new SwarmException("Hierarchical route plan [start_at] references unknown node [{$startAt}].");
        }

        $plan = new HierarchicalRoutePlan($startAt, $nodes);

        $this->validateReferences($plan);
        $this->validateParallelBranches($plan);
        $this->validateReachability($plan);
        $this->validateAcyclic($plan);
        $this->validateDataDependencies($plan);

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $workerClasses
     */
    protected function normalizeNode(string $nodeId, array $payload, string $coordinatorClass, array $workerClasses, string $swarmClass): HierarchicalRouteNode
    {
        $type = $payload['type'] ?? null;
        $metadata = $this->normalizeMetadata($payload['metadata'] ?? []);
        $next = $payload['next'] ?? null;

        if ($next !== null && ! is_string($next)) {
            throw new SwarmException("Hierarchical route node [{$nodeId}] must define [next] as a string node id.");
        }

        if (! is_string($type) || $type === '') {
            throw new SwarmException("Hierarchical route node [{$nodeId}] must define a [type].");
        }

        return match ($type) {
            'worker' => $this->normalizeWorkerNode($nodeId, $payload, $metadata, $next, $coordinatorClass, $workerClasses),
            'parallel' => $this->normalizeParallelNode($nodeId, $payload, $metadata, $next, $swarmClass),
            'finish' => $this->normalizeFinishNode($nodeId, $payload, $metadata),
            default => throw new SwarmException("Hierarchical route node [{$nodeId}] uses unsupported type [{$type}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $workerClasses
     */
    protected function normalizeWorkerNode(string $nodeId, array $payload, array $metadata, ?string $next, string $coordinatorClass, array $workerClasses): HierarchicalWorkerNode
    {
        $agentClass = $payload['agent'] ?? null;
        $prompt = $payload['prompt'] ?? null;
        $withOutputs = $this->normalizeWithOutputs($nodeId, $payload['with_outputs'] ?? []);

        if (! is_string($agentClass) || $agentClass === '') {
            throw new SwarmException("Hierarchical worker node [{$nodeId}] must define a non-empty [agent] class.");
        }

        if ($agentClass === $coordinatorClass) {
            throw new SwarmException("Hierarchical route node [{$nodeId}] cannot route the coordinator [{$coordinatorClass}] as a worker.");
        }

        if (! in_array($agentClass, $workerClasses, true)) {
            throw new SwarmException("Hierarchical route node [{$nodeId}] references unknown worker agent class [{$agentClass}]. Verify it is returned from agents().");
        }

        if (! is_string($prompt)) {
            throw new SwarmException("Hierarchical worker node [{$nodeId}] must define [prompt] as a string.");
        }

        return new HierarchicalWorkerNode(
            id: $nodeId,
            agentClass: $agentClass,
            prompt: $prompt,
            withOutputs: $withOutputs,
            metadata: $metadata,
            next: $next,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    protected function normalizeParallelNode(string $nodeId, array $payload, array $metadata, ?string $next, string $swarmClass): HierarchicalParallelNode
    {
        $branches = $payload['branches'] ?? null;

        if ($next === null) {
            throw new SwarmException("{$swarmClass}: parallel node [{$nodeId}] must define `next` in v1. Every parallel group must join into a subsequent node before the workflow can finish.");
        }

        if (! is_array($branches) || ! array_is_list($branches) || $branches === []) {
            throw new SwarmException("Hierarchical parallel node [{$nodeId}] must define a non-empty [branches] array.");
        }

        $normalized = [];

        foreach ($branches as $branch) {
            if (! is_string($branch) || $branch === '') {
                throw new SwarmException("Hierarchical parallel node [{$nodeId}] may only reference branch node ids as strings.");
            }

            $normalized[] = $branch;
        }

        return new HierarchicalParallelNode(
            id: $nodeId,
            branches: $normalized,
            metadata: $metadata,
            next: $next,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    protected function normalizeFinishNode(string $nodeId, array $payload, array $metadata): HierarchicalFinishNode
    {
        if (array_key_exists('next', $payload)) {
            throw new SwarmException("Hierarchical finish node [{$nodeId}] cannot define [next].");
        }

        $output = $payload['output'] ?? null;
        $outputFrom = $payload['output_from'] ?? null;

        $hasOutput = is_string($output);
        $hasOutputFrom = is_string($outputFrom);

        if ($hasOutput === $hasOutputFrom) {
            throw new SwarmException("Hierarchical finish node [{$nodeId}] must define exactly one of [output] or [output_from].");
        }

        return new HierarchicalFinishNode(
            id: $nodeId,
            output: $hasOutput ? $output : null,
            outputFrom: $hasOutputFrom ? $outputFrom : null,
            metadata: $metadata,
        );
    }

    protected function validateReferences(HierarchicalRoutePlan $plan): void
    {
        foreach ($plan->nodes as $node) {
            foreach ($node->controlEdges() as $edge) {
                if (! array_key_exists($edge, $plan->nodes)) {
                    throw new SwarmException("Hierarchical route node [{$node->id}] references unknown node [{$edge}].");
                }
            }

            if ($node instanceof HierarchicalWorkerNode) {
                foreach ($node->withOutputs as $alias => $sourceNodeId) {
                    if (! array_key_exists($sourceNodeId, $plan->nodes)) {
                        throw new SwarmException("Hierarchical worker node [{$node->id}] maps output alias [{$alias}] from unknown node [{$sourceNodeId}].");
                    }
                }
            }

            if ($node instanceof HierarchicalFinishNode && $node->outputFrom !== null && ! array_key_exists($node->outputFrom, $plan->nodes)) {
                throw new SwarmException("Hierarchical finish node [{$node->id}] references unknown output node [{$node->outputFrom}].");
            }
        }
    }

    protected function validateParallelBranches(HierarchicalRoutePlan $plan): void
    {
        foreach ($plan->nodes as $node) {
            if (! $node instanceof HierarchicalParallelNode) {
                continue;
            }

            foreach ($node->branches as $branchNodeId) {
                $branch = $plan->node($branchNodeId);

                if (! $branch instanceof HierarchicalWorkerNode) {
                    throw new SwarmException("Hierarchical parallel node [{$node->id}] may only reference worker nodes in [branches].");
                }

                if ($branch->next !== null) {
                    throw new SwarmException("Hierarchical worker node [{$branch->id}] cannot define [next] when used as a parallel branch.");
                }
            }
        }
    }

    protected function validateReachability(HierarchicalRoutePlan $plan): void
    {
        $reachable = [];
        $this->markReachable($plan->startAt, $plan, $reachable);

        foreach (array_keys($plan->nodes) as $nodeId) {
            if (! isset($reachable[$nodeId])) {
                throw new SwarmException("Hierarchical route plan contains unreachable node [{$nodeId}].");
            }
        }
    }

    /**
     * @param  array<string, true>  $reachable
     */
    protected function markReachable(string $nodeId, HierarchicalRoutePlan $plan, array &$reachable): void
    {
        if (isset($reachable[$nodeId])) {
            return;
        }

        $reachable[$nodeId] = true;

        foreach ($plan->node($nodeId)->controlEdges() as $nextNodeId) {
            $this->markReachable($nextNodeId, $plan, $reachable);
        }
    }

    protected function validateAcyclic(HierarchicalRoutePlan $plan): void
    {
        $visited = [];
        $inProgress = [];

        if ($this->hasCycle($plan->startAt, $plan, $visited, $inProgress)) {
            throw new SwarmException('Hierarchical route plans must be acyclic. Loops are not supported in this release.');
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

    protected function validateDataDependencies(HierarchicalRoutePlan $plan): void
    {
        $completed = [];

        $this->validateNodeDataDependencies($plan->startAt, $plan, $completed);
    }

    /**
     * @param  array<string, true>  $completed
     */
    protected function validateNodeDataDependencies(string $nodeId, HierarchicalRoutePlan $plan, array &$completed): void
    {
        $node = $plan->node($nodeId);

        if ($node instanceof HierarchicalWorkerNode) {
            $this->assertWorkerOutputsCompleted($node, $completed);
            $completed[$node->id] = true;

            if ($node->next !== null) {
                $this->validateNodeDataDependencies($node->next, $plan, $completed);
            }

            return;
        }

        if ($node instanceof HierarchicalParallelNode) {
            $groupCompleted = $completed;

            foreach ($node->branches as $branchNodeId) {
                /** @var HierarchicalWorkerNode $branch */
                $branch = $plan->node($branchNodeId);
                $this->assertWorkerOutputsCompleted($branch, $completed);
                $groupCompleted[$branch->id] = true;
            }

            $completed = $groupCompleted;

            if ($node->next !== null) {
                $this->validateNodeDataDependencies($node->next, $plan, $completed);
            }

            return;
        }

        /** @var HierarchicalFinishNode $node */
        if ($node->outputFrom !== null && ! isset($completed[$node->outputFrom])) {
            throw new SwarmException("Hierarchical finish node [{$node->id}] cannot reference output from [{$node->outputFrom}] before that node has completed.");
        }
    }

    /**
     * @param  array<string, true>  $completed
     */
    protected function assertWorkerOutputsCompleted(HierarchicalWorkerNode $node, array $completed): void
    {
        foreach ($node->withOutputs as $alias => $sourceNodeId) {
            if (! isset($completed[$sourceNodeId])) {
                throw new SwarmException("Hierarchical worker node [{$node->id}] cannot map output alias [{$alias}] from [{$sourceNodeId}] before that node has completed.");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeMetadata(mixed $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        if (! is_array($metadata)) {
            throw new SwarmException('Hierarchical route node metadata must be an object.');
        }

        return $metadata;
    }

    /**
     * @return array<string, string>
     */
    protected function normalizeWithOutputs(string $nodeId, mixed $withOutputs): array
    {
        if ($withOutputs === null || $withOutputs === []) {
            return [];
        }

        if (! is_array($withOutputs) || array_is_list($withOutputs)) {
            throw new SwarmException("Hierarchical worker node [{$nodeId}] must define [with_outputs] as an object keyed by alias.");
        }

        $normalized = [];

        foreach ($withOutputs as $alias => $sourceNodeId) {
            if (! is_string($alias) || $alias === '') {
                throw new SwarmException("Hierarchical worker node [{$nodeId}] output aliases must be non-empty strings.");
            }

            if (! is_string($sourceNodeId) || $sourceNodeId === '') {
                throw new SwarmException("Hierarchical worker node [{$nodeId}] must map output alias [{$alias}] to a non-empty node id.");
            }

            $normalized[$alias] = $sourceNodeId;
        }

        return $normalized;
    }
}
