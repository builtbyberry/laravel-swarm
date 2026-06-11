<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

/**
 * @internal
 */
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

    /**
     * Worst-case number of worker executions the plan can require, including
     * bounded loop replays. A looped worker can replay its loop body up to
     * `max_iterations - 1` additional times; this counts those replays so the
     * execution budget gate reflects the worst case rather than a single pass.
     */
    public function reachableWorkerCount(): int
    {
        $visited = [];
        $count = $this->countReachableWorkers($this->startAt, $visited);

        foreach ($this->nodes as $node) {
            if (! $node instanceof HierarchicalWorkerNode || ! $node->hasLoop() || ! isset($visited[$node->id])) {
                continue;
            }

            $count += ((int) $node->loopMaxIterations - 1) * $this->loopBodyWorkerCount((string) $node->loopTo, $node->id);
        }

        return $count;
    }

    /**
     * Count worker nodes on the forward loop body between the loop target and
     * the looping node (inclusive) — the set of workers that re-execute on
     * each additional loop iteration.
     */
    protected function loopBodyWorkerCount(string $loopTo, string $loopNodeId): int
    {
        $count = 0;
        $seen = [];
        $stack = [$loopTo];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $node = $this->node($current);

            if ($node instanceof HierarchicalWorkerNode) {
                $count++;
            } elseif ($node instanceof HierarchicalParallelNode) {
                $count += count($node->branches);
            }

            if ($current === $loopNodeId) {
                continue;
            }

            foreach ($node->controlEdges() as $edge) {
                $stack[] = $edge;
            }
        }

        return $count;
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
                    'loop' => $node->hasLoop()
                        ? ['to' => $node->loopTo, 'max_iterations' => $node->loopMaxIterations]
                        : null,
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
        $plan->validatePersistedLoops();
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
            'worker' => self::workerFromArray($nodeId, $payload, $metadata, $next),
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
     * @param  array<string, mixed>  $metadata
     */
    protected static function workerFromArray(string $nodeId, array $payload, array $metadata, ?string $next): HierarchicalWorkerNode
    {
        [$loopTo, $loopMaxIterations] = self::loopFromArray($nodeId, $payload['loop'] ?? null, $next);

        return new HierarchicalWorkerNode(
            id: $nodeId,
            agentClass: self::requiredString($payload, 'agent', $nodeId),
            prompt: self::requiredString($payload, 'prompt', $nodeId),
            withOutputs: self::optionalStringMap($payload, 'with_outputs', $nodeId),
            metadata: $metadata,
            next: $next,
            loopTo: $loopTo,
            loopMaxIterations: $loopMaxIterations,
        );
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    protected static function loopFromArray(string $nodeId, mixed $loop, ?string $next): array
    {
        if ($loop === null) {
            return [null, null];
        }

        if (! is_array($loop) || array_is_list($loop)) {
            throw new SwarmException("Persisted hierarchical worker node [{$nodeId}] must define [loop] as an object with [to] and [max_iterations].");
        }

        $to = $loop['to'] ?? null;
        $maxIterations = $loop['max_iterations'] ?? null;

        if (! is_string($to) || $to === '') {
            throw new SwarmException("Persisted hierarchical worker node [{$nodeId}] must define [loop.to] as a non-empty node id.");
        }

        if (! is_int($maxIterations) || $maxIterations < 1) {
            throw new SwarmException("Persisted hierarchical worker node [{$nodeId}] must define [loop.max_iterations] as a positive integer.");
        }

        if ($next === null) {
            throw new SwarmException("Persisted hierarchical worker node [{$nodeId}] with a [loop] must also define [next] as the loop's exit node.");
        }

        return [$to, $maxIterations];
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

    protected function validatePersistedLoops(): void
    {
        $branchNodeIds = [];

        foreach ($this->nodes as $node) {
            if ($node instanceof HierarchicalParallelNode) {
                foreach ($node->branches as $branchNodeId) {
                    $branchNodeIds[$branchNodeId] = true;
                }
            }
        }

        foreach ($this->nodes as $node) {
            if (! $node instanceof HierarchicalWorkerNode || ! $node->hasLoop()) {
                continue;
            }

            if (isset($branchNodeIds[$node->id])) {
                throw new SwarmException("Persisted hierarchical worker node [{$node->id}] cannot define a [loop] while used as a parallel branch.");
            }

            $target = $this->node((string) $node->loopTo);

            if (! $target instanceof HierarchicalWorkerNode) {
                throw new SwarmException("Persisted hierarchical worker node [{$node->id}] may only loop back to a worker node, not [{$node->loopTo}].");
            }

            if (! $this->controlReaches((string) $node->loopTo, $node->id)) {
                throw new SwarmException("Persisted hierarchical worker node [{$node->id}] must loop back to an earlier node on its own path; [{$node->loopTo}] does not reach [{$node->id}].");
            }
        }
    }

    protected function controlReaches(string $fromNodeId, string $targetNodeId): bool
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

            foreach ($this->node($current)->controlEdges() as $edge) {
                $stack[] = $edge;
            }
        }

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
