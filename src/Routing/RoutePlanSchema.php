<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Reusable JsonSchema builders for a hierarchical coordinator's route plan.
 *
 * A hierarchical coordinator declares its route-plan shape in `schema()`. Left
 * to `nodes => $schema->object()`, that shape is opaque — the model is free to
 * emit any node payload and {@see HierarchicalRoutePlanner} rejects malformed
 * plans only *after* generation. These helpers turn each node variant the
 * planner accepts (worker, rollup, parallel, finish) into a precise JsonSchema
 * type, and combine them with `anyOf` so the model self-constrains to a valid
 * node shape up front.
 *
 * `laravel/ai` ^0.9 preserves `anyOf` end-to-end (its `SchemaNormalizer` keeps
 * the composition instead of collapsing it), so these unions reach the provider
 * intact.
 *
 * **The planner stays authoritative.** These schemas describe per-node *shape*
 * only — they cannot express the graph-level invariants the planner still
 * enforces (reachability from `start_at`, DAG acyclicity, data-dependency
 * ordering, loop bounds). Treat them as a first line of defense that makes a
 * well-formed plan the easy path, not a replacement for validation.
 *
 * `JsonSchema\Types\ObjectType` types named properties only — it cannot type the
 * values of a free-form map. So compose these where the plan's node ids are a
 * known skeleton (`$schema->object(['classify' => node(), 'done' => finish()])`);
 * for a fully dynamic node map, `nodes => $schema->object()` plus the planner's
 * validation remains the fallback.
 */
final class RoutePlanSchema
{
    /**
     * A worker node: run one agent with a prompt, then continue to `next`.
     */
    public static function worker(JsonSchema $schema): Type
    {
        return $schema->object([
            'type' => $schema->string()->enum(['worker'])->required(),
            'agent' => $schema->string()
                ->description('Fully-qualified worker agent class to run for this node.')
                ->required(),
            'prompt' => $schema->string()
                ->description('The prompt handed to the worker agent.')
                ->required(),
            'next' => $schema->string()
                ->description('Id of the node to run after this worker completes.')
                ->required(),
        ]);
    }

    /**
     * A rollup node: a worker that digests the generation(s) named by
     * `with_outputs`. Like a worker but it must name what it digests and cannot
     * carry a loop of its own.
     */
    public static function rollup(JsonSchema $schema): Type
    {
        return $schema->object([
            'type' => $schema->string()->enum(['rollup'])->required(),
            'agent' => $schema->string()
                ->description('Fully-qualified agent class that digests the named generations.')
                ->required(),
            'prompt' => $schema->string()
                ->description('The prompt handed to the rollup agent.')
                ->required(),
            'with_outputs' => $schema->array()
                ->items($schema->string())
                ->min(1)
                ->description('Ids of the node generations this rollup digests.')
                ->required(),
            'next' => $schema->string()
                ->description('Id of the node to run after this rollup completes.')
                ->required(),
        ]);
    }

    /**
     * A parallel node: fan out to the worker nodes named in `branches`, joining
     * at `next` once every branch completes.
     */
    public static function parallel(JsonSchema $schema): Type
    {
        return $schema->object([
            'type' => $schema->string()->enum(['parallel'])->required(),
            'branches' => $schema->array()
                ->items($schema->string())
                ->min(1)
                ->description('Ids of the worker nodes to run concurrently.')
                ->required(),
            'next' => $schema->string()
                ->description('The join node to run after all branches complete.')
                ->required(),
        ]);
    }

    /**
     * A finish node: end the run, returning **exactly one** of `output` (a
     * literal answer) or `output_from` (another node's generation). The
     * exactly-one-of rule is expressed as an `anyOf` of the two variants.
     */
    public static function finish(JsonSchema $schema): Type
    {
        return $schema->anyOf([
            self::finishWithOutput($schema),
            self::finishWithOutputFrom($schema),
        ]);
    }

    /**
     * The full node discriminated union: a node is one of worker, rollup,
     * parallel, or finish. Use this to type each slot of a known node skeleton.
     */
    public static function node(JsonSchema $schema): Type
    {
        return $schema->anyOf([
            self::worker($schema),
            self::rollup($schema),
            self::parallel($schema),
            self::finishWithOutput($schema),
            self::finishWithOutputFrom($schema),
        ]);
    }

    /**
     * The `finish` variant that returns a literal `output`.
     */
    private static function finishWithOutput(JsonSchema $schema): Type
    {
        return $schema->object([
            'type' => $schema->string()->enum(['finish'])->required(),
            'output' => $schema->string()
                ->description('A literal final answer to return from the run.')
                ->required(),
        ]);
    }

    /**
     * The `finish` variant that returns another node's generation via
     * `output_from`.
     */
    private static function finishWithOutputFrom(JsonSchema $schema): Type
    {
        return $schema->object([
            'type' => $schema->string()->enum(['finish'])->required(),
            'output_from' => $schema->string()
                ->description("Id of the node whose generation becomes the run's output.")
                ->required(),
        ]);
    }
}
