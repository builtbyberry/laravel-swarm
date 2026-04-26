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

        return new self($startAt, $nodes);
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
                next: $next,
            ),
            'finish' => new HierarchicalFinishNode(
                id: $nodeId,
                output: self::optionalString($payload, 'output', $nodeId),
                outputFrom: self::optionalString($payload, 'output_from', $nodeId),
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
}
