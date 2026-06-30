<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

/**
 * A worker node that additionally digests a generation (#289).
 *
 * A rollup runs a digester agent exactly like a {@see HierarchicalWorkerNode} —
 * its `with_outputs` both feeds the digester's prompt AND names the generation it
 * digests. After the digest output is recorded, the streaming walk prunes those
 * source nodes from the operational context map, appends `rolled_up` void-edges so
 * the read-time fold suppresses them, and seals the window for mid-run compaction.
 *
 * Extending the worker node is deliberate: the executor's
 * `$node instanceof HierarchicalWorkerNode` branch runs the digester with no new
 * execution path, and the rollup-only effects are gated on this subtype afterwards.
 * A rollup carries no loop of its own — it sits on a loop body's forward path and
 * re-runs per iteration when an enclosing worker loops back.
 *
 * @internal
 */
class HierarchicalRollupNode extends HierarchicalWorkerNode
{
    /**
     * @param  array<string, string>  $withOutputs
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $id,
        string $agentClass,
        string $prompt,
        array $withOutputs = [],
        array $metadata = [],
        ?string $next = null,
    ) {
        parent::__construct(
            id: $id,
            agentClass: $agentClass,
            prompt: $prompt,
            withOutputs: $withOutputs,
            metadata: $metadata,
            next: $next,
            loopTo: null,
            loopMaxIterations: null,
            // A rollup is its own kind so the fold, planner, and
            // (de)serialization can tell it apart from a plain worker.
            type: 'rollup',
        );
    }

    /**
     * The node ids whose outputs this rollup digests — its `with_outputs`
     * sources. These become context-unreferenceable to every later node once the
     * rollup runs (enforced at plan-materialization).
     *
     * @return array<int, string>
     */
    public function digestedNodeIds(): array
    {
        return array_values(array_unique($this->withOutputs));
    }
}
