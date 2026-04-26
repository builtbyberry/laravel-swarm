<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

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
        if (! array_key_exists($nodeId, $this->nodes)) {
            throw new SwarmException("Hierarchical route plan references unknown node [{$nodeId}].");
        }

        return $this->nodes[$nodeId];
    }

    public function reachableWorkerCount(): int
    {
        $visited = [];

        return $this->countReachableWorkers($this->startAt, $visited);
    }

    /**
     * @return array{start_at: string, nodes: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        $nodes = [];

        foreach ($this->nodes as $nodeId => $node) {
            $payload = [
                'type' => $node->type,
                'metadata' => $node->metadata,
            ];

            if ($node instanceof HierarchicalWorkerNode) {
                $payload = array_merge($payload, [
                    'agent' => $node->agentClass,
                    'prompt' => $node->prompt,
                    'with_outputs' => $node->withOutputs,
                    'next' => $node->next,
                ]);
            } elseif ($node instanceof HierarchicalParallelNode) {
                $payload = array_merge($payload, [
                    'branches' => $node->branches,
                    'next' => $node->next,
                ]);
            } elseif ($node instanceof HierarchicalFinishNode) {
                $payload = array_merge($payload, [
                    'output' => $node->output,
                    'output_from' => $node->outputFrom,
                ]);
            }

            $nodes[$nodeId] = array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== []);
        }

        return [
            'start_at' => $this->startAt,
            'nodes' => $nodes,
        ];
    }

    /**
     * @param  array{start_at?: mixed, nodes?: mixed}  $payload
     */
    public static function fromArray(array $payload): self
    {
        $startAt = $payload['start_at'] ?? null;
        $nodesPayload = $payload['nodes'] ?? null;

        if (! is_string($startAt) || $startAt === '') {
            throw new SwarmException('Persisted hierarchical route plan must define a non-empty [start_at] node id.');
        }

        if (! is_array($nodesPayload) || array_is_list($nodesPayload)) {
            throw new SwarmException('Persisted hierarchical route plan must define [nodes] as an object keyed by node id.');
        }

        $nodes = [];

        foreach ($nodesPayload as $nodeId => $nodePayload) {
            if (! is_string($nodeId) || $nodeId === '') {
                throw new SwarmException('Persisted hierarchical route plan node ids must be non-empty strings.');
            }

            if (! is_array($nodePayload)) {
                throw new SwarmException("Persisted hierarchical route node [{$nodeId}] must be an object.");
            }

            $nodes[$nodeId] = self::nodeFromArray($nodeId, $nodePayload);
        }

        if (! array_key_exists($startAt, $nodes)) {
            throw new SwarmException("Persisted hierarchical route plan [start_at] references unknown node [{$startAt}].");
        }

        $plan = new self($startAt, $nodes);

        $plan->validatePersistedReferences();
        $plan->validatePersistedParallelBranches();
        $plan->validatePersistedReachability();
        $plan->validatePersistedAcyclic();
        $plan->validatePersistedDataDependencies();

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function nodeFromArray(string $nodeId, array $payload): HierarchicalRouteNode
    {
        $type = $payload['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw new SwarmException("Persisted hierarchical route node [{$nodeId}] must define a [type].");
        }

        $metadata = self::optionalArray($payload, 'metadata', $nodeId);
        $next = self::optionalString($payload, 'next', $nodeId);

        return match ($type) {
            'worker' => new HierarchicalWorkerNode(
                id: $nodeId,
                agentClass: self::requiredString($payload, 'agent', $nodeId),
                prompt: self::requiredString($payload, 'prompt', $nodeId),
                withOutputs: self::optionalStringMap($payload, 'with_outputs', $nodeId),
                metadata: $metadata,
                next: $next,
            ),
            'parallel' => new HierarchicalParallelNode(
                id: $nodeId,
                branches: self::requiredStringList($payload, 'branches', $nodeId),
                metadata: $metadata,
                next: self::requiredString($payload, 'next', $nodeId),
            ),
            'finish' => new HierarchicalFinishNode(
                id: $nodeId,
                output: self::finishOutput($payload, $nodeId),
                outputFrom: self::finishOutputFrom($payload, $nodeId),
                metadata: $metadata,
            ),
            default => throw new SwarmException("Persisted hierarchical route node [{$nodeId}] uses unsupported type [{$type}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function requiredString(array $payload, string $key, string $nodeId): string
    {
        if (! is_string($payload[$key] ?? null) || $payload[$key] === '') {
            throw new SwarmException("Persisted hierarchical route node [{$nodeId}] must define [{$key}] as a non-empty string.");
        }

        return $payload[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function optionalString(array $payload, string $key, string $nodeId): ?string
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        if (! is_string($payload[$key])) {
            throw new SwarmException("Persisted hierarchical route node [{$nodeId}] must define [{$key}] as a string.");
        }

        return $payload[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function optionalArray(array $payload, string $key, string $nodeId): array
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return [];
        }

        if (! is_array($payload[$key])) {
            throw new SwarmException("Persisted hierarchical route node [{$nodeId}] must define [{$key}] as an object.");
        }

        return $payload[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected static function optionalStringMap(array $payload, string $key, string $nodeId): array
    {
        $value = self::optionalArray($payload, $key, $nodeId);

        foreach ($value as $alias => $sourceNodeId) {
            if (! is_string($alias) || $alias === '' || ! is_string($sourceNodeId) || $sourceNodeId === '') {
                throw new SwarmException("Persisted hierarchical route node [{$nodeId}] must define [{$key}] as a string map.");
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function finishOutput(array $payload, string $nodeId): ?string
    {
        $output = self::optionalString($payload, 'output', $nodeId);
        $outputFrom = self::optionalString($payload, 'output_from', $nodeId);

        if (($output !== null) === ($outputFrom !== null)) {
            throw new SwarmException("Persisted hierarchical finish node [{$nodeId}] must define exactly one of [output] or [output_from].");
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function finishOutputFrom(array $payload, string $nodeId): ?string
    {
        $output = self::optionalString($payload, 'output', $nodeId);
        $outputFrom = self::optionalString($payload, 'output_from', $nodeId);

        if (($output !== null) === ($outputFrom !== null)) {
            throw new SwarmException("Persisted hierarchical finish node [{$nodeId}] must define exactly one of [output] or [output_from].");
        }

        return $outputFrom;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    protected static function requiredStringList(array $payload, string $key, string $nodeId): array
    {
        if (! is_array($payload[$key] ?? null) || ! array_is_list($payload[$key])) {
            throw new SwarmException("Persisted hierarchical route node [{$nodeId}] must define [{$key}] as a list of strings.");
        }

        foreach ($payload[$key] as $value) {
            if (! is_string($value) || $value === '') {
                throw new SwarmException("Persisted hierarchical route node [{$nodeId}] must define [{$key}] as a list of non-empty strings.");
            }
        }

        return $payload[$key];
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

    protected function validatePersistedReferences(): void
    {
        foreach ($this->nodes as $node) {
            foreach ($node->controlEdges() as $edge) {
                if (! array_key_exists($edge, $this->nodes)) {
                    throw new SwarmException("Persisted hierarchical route node [{$node->id}] references unknown node [{$edge}].");
                }
            }

            if ($node instanceof HierarchicalWorkerNode) {
                foreach ($node->withOutputs as $alias => $sourceNodeId) {
                    if (! array_key_exists($sourceNodeId, $this->nodes)) {
                        throw new SwarmException("Persisted hierarchical worker node [{$node->id}] maps output alias [{$alias}] from unknown node [{$sourceNodeId}].");
                    }
                }
            }

            if ($node instanceof HierarchicalFinishNode && $node->outputFrom !== null && ! array_key_exists($node->outputFrom, $this->nodes)) {
                throw new SwarmException("Persisted hierarchical finish node [{$node->id}] references unknown output node [{$node->outputFrom}].");
            }
        }
    }

    protected function validatePersistedParallelBranches(): void
    {
        foreach ($this->nodes as $node) {
            if (! $node instanceof HierarchicalParallelNode) {
                continue;
            }

            foreach ($node->branches as $branchNodeId) {
                $branch = $this->node($branchNodeId);

                if (! $branch instanceof HierarchicalWorkerNode) {
                    throw new SwarmException("Persisted hierarchical parallel node [{$node->id}] may only reference worker nodes in [branches].");
                }

                if ($branch->next !== null) {
                    throw new SwarmException("Persisted hierarchical worker node [{$branch->id}] cannot define [next] when used as a parallel branch.");
                }
            }
        }
    }

    protected function validatePersistedReachability(): void
    {
        $reachable = [];
        $this->markPersistedReachable($this->startAt, $reachable);

        foreach (array_keys($this->nodes) as $nodeId) {
            if (! isset($reachable[$nodeId])) {
                throw new SwarmException("Persisted hierarchical route plan contains unreachable node [{$nodeId}].");
            }
        }
    }

    /**
     * @param  array<string, true>  $reachable
     */
    protected function markPersistedReachable(string $nodeId, array &$reachable): void
    {
        if (isset($reachable[$nodeId])) {
            return;
        }

        $reachable[$nodeId] = true;

        foreach ($this->node($nodeId)->controlEdges() as $nextNodeId) {
            $this->markPersistedReachable($nextNodeId, $reachable);
        }
    }

    protected function validatePersistedAcyclic(): void
    {
        $visited = [];
        $inProgress = [];

        if ($this->hasPersistedCycle($this->startAt, $visited, $inProgress)) {
            throw new SwarmException('Persisted hierarchical route plans must be acyclic. Loops are not supported in durable recovery.');
        }
    }

    /**
     * @param  array<string, bool>  $visited
     * @param  array<string, bool>  $inProgress
     */
    protected function hasPersistedCycle(string $nodeId, array &$visited, array &$inProgress): bool
    {
        $visited[$nodeId] = true;
        $inProgress[$nodeId] = true;

        foreach ($this->node($nodeId)->controlEdges() as $nextNodeId) {
            if (! isset($visited[$nextNodeId])) {
                if ($this->hasPersistedCycle($nextNodeId, $visited, $inProgress)) {
                    return true;
                }
            } elseif (isset($inProgress[$nextNodeId])) {
                return true;
            }
        }

        unset($inProgress[$nodeId]);

        return false;
    }

    protected function validatePersistedDataDependencies(): void
    {
        $completed = [];

        $this->validatePersistedNodeDataDependencies($this->startAt, $completed);
    }

    /**
     * @param  array<string, true>  $completed
     */
    protected function validatePersistedNodeDataDependencies(string $nodeId, array &$completed): void
    {
        $node = $this->node($nodeId);

        if ($node instanceof HierarchicalWorkerNode) {
            $this->assertPersistedWorkerOutputsCompleted($node, $completed);
            $completed[$node->id] = true;

            if ($node->next !== null) {
                $this->validatePersistedNodeDataDependencies($node->next, $completed);
            }

            return;
        }

        if ($node instanceof HierarchicalParallelNode) {
            $groupCompleted = $completed;

            foreach ($node->branches as $branchNodeId) {
                /** @var HierarchicalWorkerNode $branch */
                $branch = $this->node($branchNodeId);
                $this->assertPersistedWorkerOutputsCompleted($branch, $completed);
                $groupCompleted[$branch->id] = true;
            }

            $completed = $groupCompleted;

            if ($node->next !== null) {
                $this->validatePersistedNodeDataDependencies($node->next, $completed);
            }

            return;
        }

        /** @var HierarchicalFinishNode $node */
        if ($node->outputFrom !== null && ! isset($completed[$node->outputFrom])) {
            throw new SwarmException("Persisted hierarchical finish node [{$node->id}] cannot reference output from [{$node->outputFrom}] before that node has completed.");
        }
    }

    /**
     * @param  array<string, true>  $completed
     */
    protected function assertPersistedWorkerOutputsCompleted(HierarchicalWorkerNode $node, array $completed): void
    {
        foreach ($node->withOutputs as $alias => $sourceNodeId) {
            if (! isset($completed[$sourceNodeId])) {
                throw new SwarmException("Persisted hierarchical worker node [{$node->id}] cannot map output alias [{$alias}] from [{$sourceNodeId}] before that node has completed.");
            }
        }
    }
}
