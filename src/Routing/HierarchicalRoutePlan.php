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
     * bounded loop replays.
     *
     * Loops compose multiplicatively: a worker inside an outer loop bounded at
     * Mo and an inner loop bounded at Mi runs up to Mo*Mi times, not Mo+Mi. This
     * walks the plan once, folding each bounded loop's body cost in as
     * `max_iterations * bodyCost` (the body cost itself already including any
     * nested loops), so the budget gate reflects the true product-of-bounds
     * worst case. Shared by every execution mode's budget and total_steps via a
     * single source of truth.
     */
    public function reachableWorkerCount(): int
    {
        $seen = [];

        return $this->weightedSegmentCost($this->startAt, null, $seen);
    }

    /**
     * Worst-case worker executions for ONE pass through the segment beginning at
     * $entry and ending after $stopNodeId (or at a finish/dead-end). Bounded
     * loops are fully expanded: a loop whose looping node is reached adds
     * `(max_iterations - 1) * bodyCost` on top of the single linear pass already
     * counted, where the body cost is itself produced by this same walk so a
     * nested inner loop is expanded before its outer multiplier applies. The net
     * effect is the product-of-bounds worst case (Mo*Mm*Mi, not Mo+Mm+Mi).
     *
     * The visited-set is shared across the whole pass so a diamond-join node is
     * counted once; a fresh set is seeded for each loop body so the body can be
     * re-costed independently of the enclosing pass.
     *
     * @param  array<string, true>  $seen
     */
    protected function weightedSegmentCost(string $entry, ?string $stopNodeId, array &$seen): int
    {
        $cost = 0;
        $current = $entry;

        while (true) {
            if (isset($seen[$current])) {
                break;
            }

            $seen[$current] = true;
            $node = $this->node($current);

            if ($node instanceof HierarchicalParallelNode) {
                foreach ($node->branches as $branchNodeId) {
                    if (! isset($seen[$branchNodeId])) {
                        $seen[$branchNodeId] = true;
                        $cost++;
                    }
                }

                if ($node->next === null) {
                    break;
                }

                $current = $node->next;

                continue;
            }

            if (! $node instanceof HierarchicalWorkerNode) {
                // Finish node — terminal.
                break;
            }

            // Every worker runs at least once on this linear pass.
            $cost++;

            // A bounded loop adds (max - 1) extra replays of its body. The body
            // is re-walked here (fresh visited-set) so any nested inner loop is
            // expanded first; multiplying that fully-expanded body by the outer
            // bound yields the product-of-bounds worst case.
            if ($node->hasLoop() && $node->id !== $stopNodeId) {
                $bodySeen = [];
                $bodyCost = $this->weightedSegmentCost((string) $node->loopTo, $node->id, $bodySeen);
                $cost += ((int) $node->loopMaxIterations - 1) * $bodyCost;
            }

            // Reached this scope's loop boundary — the body ends here.
            if ($node->id === $stopNodeId) {
                break;
            }

            if ($node->next === null) {
                break;
            }

            $current = $node->next;
        }

        return $cost;
    }

    /**
     * The looping worker nodes that live strictly inside the loop body between
     * $loopTo and $loopNodeId — i.e. every worker on the forward control path
     * that itself defines a loop, excluding the looping node $loopNodeId.
     *
     * When the outer loop re-enters (a back-edge fires), each of these inner
     * loops must have its per-node iteration counter reset to zero so it can run
     * its full count again on the next outer pass. Recomputed from the persisted
     * plan on each step — never persisted — so the reset is replay-safe.
     *
     * Uses a visited-set so diamond-join nodes on the body are walked once,
     * consistent with the durable entry-spine dedup (F3).
     *
     * @return array<int, string>
     */
    public function loopBodyLoopingNodes(string $loopTo, string $loopNodeId): array
    {
        $looping = [];
        $seen = [];
        $stack = [$loopTo];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $node = $this->node($current);

            if (
                $node instanceof HierarchicalWorkerNode
                && $node->hasLoop()
                && $node->id !== $loopNodeId
            ) {
                $looping[] = $node->id;
            }

            if ($current === $loopNodeId) {
                continue;
            }

            foreach ($node->controlEdges() as $edge) {
                $stack[] = $edge;
            }
        }

        return $looping;
    }

    /**
     * Reset the per-node loop counters of every loop nested inside this
     * back-edge's body, so each inner loop re-runs its full count on the next
     * outer pass. The single authority for the nested-loop counter-reset rule,
     * shared by the sync, durable, and streamed runners. $counters is mutated
     * in place.
     *
     * @param  array<string, int>  $counters
     */
    public function clearInnerLoopCounters(array &$counters, string $loopTo, string $loopNodeId): void
    {
        foreach ($this->loopBodyLoopingNodes($loopTo, $loopNodeId) as $inner) {
            unset($counters[$inner]);
        }
    }

    /**
     * The parallel fan-out nodes that live inside the loop body between $loopTo
     * and $loopNodeId. When the loop re-enters on a back-edge, each of these
     * parallel nodes' persisted durable branch rows must be cleared so the
     * fan-out re-dispatches its branch agents on the next pass instead of
     * re-joining pass-1's stale completed rows. Recomputed from the persisted
     * plan on each step — never persisted — so the deletion is replay-safe.
     *
     * @return array<int, string>
     */
    public function loopBodyParallelNodeIds(string $loopTo, string $loopNodeId): array
    {
        $parallels = [];

        foreach (array_keys($this->loopBodyNodeIds($loopTo, $loopNodeId)) as $nodeId) {
            if ($this->node($nodeId) instanceof HierarchicalParallelNode) {
                $parallels[] = $nodeId;
            }
        }

        return $parallels;
    }

    /**
     * The innermost bounded-loop worker whose loop body contains $nodeId, or
     * null if $nodeId sits outside every loop. Used to thread the enclosing
     * loop's current iteration onto steps (e.g. parallel branch steps) that do
     * not themselves own a loop counter. "Innermost" is the looping node whose
     * body is smallest among those that contain $nodeId.
     */
    public function enclosingLoopNodeId(string $nodeId): ?string
    {
        $best = null;
        $bestSize = PHP_INT_MAX;

        foreach ($this->nodes as $node) {
            if (! $node instanceof HierarchicalWorkerNode || ! $node->hasLoop()) {
                continue;
            }

            $body = $this->loopBodyNodeIds((string) $node->loopTo, $node->id);

            if (! isset($body[$nodeId])) {
                continue;
            }

            if (count($body) < $bestSize) {
                $best = $node->id;
                $bestSize = count($body);
            }
        }

        return $best;
    }

    /**
     * The set of node ids on the forward control path from $loopTo to
     * $loopNodeId (inclusive), excluding loop back-edges.
     *
     * @return array<string, true>
     */
    protected function loopBodyNodeIds(string $loopTo, string $loopNodeId): array
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
            $node = $this->node($current);

            if ($node instanceof HierarchicalParallelNode) {
                foreach ($node->branches as $branchNodeId) {
                    $body[$branchNodeId] = true;
                }
            }

            if ($current === $loopNodeId) {
                continue;
            }

            foreach ($node->controlEdges() as $edge) {
                $stack[] = $edge;
            }
        }

        return $body;
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
            'rollup' => self::rollupFromArray($nodeId, $payload, $metadata, $next),
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    protected static function rollupFromArray(string $nodeId, array $payload, array $metadata, ?string $next): HierarchicalRollupNode
    {
        if (array_key_exists('loop', $payload)) {
            throw new SwarmException("Persisted hierarchical rollup node [{$nodeId}] cannot define a [loop].");
        }

        $withOutputs = self::optionalStringMap($payload, 'with_outputs', $nodeId);

        if ($withOutputs === []) {
            throw new SwarmException("Persisted hierarchical rollup node [{$nodeId}] must define [with_outputs] naming the generation it digests.");
        }

        return new HierarchicalRollupNode(
            id: $nodeId,
            agentClass: self::requiredString($payload, 'agent', $nodeId),
            prompt: self::requiredString($payload, 'prompt', $nodeId),
            withOutputs: $withOutputs,
            metadata: $metadata,
            next: $next,
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
        (new LoopRuleValidator)->assertAcyclic(
            $this,
            'Persisted hierarchical route plans must be acyclic. Loops are not supported in durable recovery.',
        );
    }

    protected function validatePersistedLoops(): void
    {
        (new LoopRuleValidator)->assertLoops($this, 'Persisted hierarchical');
    }

    protected function validatePersistedDataDependencies(): void
    {
        $completed = [];
        $rolledUp = [];

        $this->validatePersistedNodeDataDependencies($this->startAt, $completed, $rolledUp);
    }

    /**
     * @param  array<string, true>  $completed
     * @param  array<string, string>  $rolledUp  digested node id => the rollup node that digested it
     */
    protected function validatePersistedNodeDataDependencies(string $nodeId, array &$completed, array &$rolledUp): void
    {
        $node = $this->node($nodeId);

        if ($node instanceof HierarchicalWorkerNode) {
            $this->assertPersistedWorkerOutputsCompleted($node, $completed, $rolledUp);
            $completed[$node->id] = true;

            if ($node instanceof HierarchicalRollupNode) {
                foreach ($node->digestedNodeIds() as $digestedId) {
                    $rolledUp[$digestedId] = $node->id;
                }
            }

            if ($node->next !== null) {
                $this->validatePersistedNodeDataDependencies($node->next, $completed, $rolledUp);
            }

            return;
        }

        if ($node instanceof HierarchicalParallelNode) {
            $groupCompleted = $completed;

            foreach ($node->branches as $branchNodeId) {
                /** @var HierarchicalWorkerNode $branch */
                $branch = $this->node($branchNodeId);
                $this->assertPersistedWorkerOutputsCompleted($branch, $completed, $rolledUp);
                $groupCompleted[$branch->id] = true;
            }

            $completed = $groupCompleted;

            if ($node->next !== null) {
                $this->validatePersistedNodeDataDependencies($node->next, $completed, $rolledUp);
            }

            return;
        }

        /** @var HierarchicalFinishNode $node */
        if ($node->outputFrom !== null && ! isset($completed[$node->outputFrom])) {
            throw new SwarmException("Persisted hierarchical finish node [{$node->id}] cannot reference output from [{$node->outputFrom}] before that node has completed.");
        }

        if ($node->outputFrom !== null && isset($rolledUp[$node->outputFrom])) {
            throw new SwarmException("Persisted hierarchical finish node [{$node->id}] references [{$node->outputFrom}], which was rolled up by [{$rolledUp[$node->outputFrom]}]; reference the rollup node [{$rolledUp[$node->outputFrom]}] instead.");
        }
    }

    /**
     * @param  array<string, true>  $completed
     * @param  array<string, string>  $rolledUp
     */
    protected function assertPersistedWorkerOutputsCompleted(HierarchicalWorkerNode $node, array $completed, array $rolledUp): void
    {
        foreach ($node->withOutputs as $alias => $sourceNodeId) {
            if (! isset($completed[$sourceNodeId])) {
                throw new SwarmException("Persisted hierarchical worker node [{$node->id}] cannot map output alias [{$alias}] from [{$sourceNodeId}] before that node has completed.");
            }

            if (isset($rolledUp[$sourceNodeId])) {
                throw new SwarmException("Persisted hierarchical worker node [{$node->id}] maps output alias [{$alias}] from [{$sourceNodeId}], which was rolled up by [{$rolledUp[$sourceNodeId]}]; reference the rollup node [{$rolledUp[$sourceNodeId]}] instead.");
            }
        }
    }
}
