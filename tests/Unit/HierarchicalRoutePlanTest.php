<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRollupNode;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalWorkerNode;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

test('persisted route plan hydration rejects unknown control references', function () {
    expect(fn () => HierarchicalRoutePlan::fromArray([
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
                'next' => 'missing_node',
            ],
        ],
    ]))->toThrow(SwarmException::class, 'Persisted hierarchical route node [writer_node] references unknown node [missing_node].');
});

test('persisted route plan hydration rejects unknown output dependencies', function () {
    expect(fn () => HierarchicalRoutePlan::fromArray([
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
                'with_outputs' => ['draft' => 'missing_node'],
            ],
        ],
    ]))->toThrow(SwarmException::class, 'Persisted hierarchical worker node [writer_node] maps output alias [draft] from unknown node [missing_node].');
});

test('persisted route plan hydration rejects parallel nodes without a join target', function () {
    expect(fn () => HierarchicalRoutePlan::fromArray([
        'start_at' => 'parallel_node',
        'nodes' => [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node'],
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
            ],
        ],
    ]))->toThrow(SwarmException::class, 'Persisted hierarchical route node [parallel_node] must define [next] as a non-empty string.');
});

test('persisted route plan hydration rejects invalid finish nodes', function () {
    expect(fn () => HierarchicalRoutePlan::fromArray([
        'start_at' => 'finish_node',
        'nodes' => [
            'finish_node' => [
                'type' => 'finish',
            ],
        ],
    ]))->toThrow(SwarmException::class, 'Persisted hierarchical finish node [finish_node] must define exactly one of [output] or [output_from].');
});

test('persisted route plan hydration rejects invalid data dependency order', function () {
    expect(fn () => HierarchicalRoutePlan::fromArray([
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
                'next' => 'editor_node',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'Edit.',
                'with_outputs' => ['future' => 'finish_node'],
                'next' => 'finish_node',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ],
    ]))->toThrow(SwarmException::class, 'Persisted hierarchical worker node [editor_node] cannot map output alias [future] from [finish_node] before that node has completed.');
});

test('persisted route plan hydration accepts a bounded loop back-edge', function () {
    $plan = HierarchicalRoutePlan::fromArray([
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
                'next' => 'finish_node',
                'loop' => ['to' => 'writer_node', 'max_iterations' => 3],
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'writer_node',
            ],
        ],
    ]);

    $writer = $plan->node('writer_node');

    expect($writer)->toBeInstanceOf(HierarchicalWorkerNode::class)
        ->and($writer->hasLoop())->toBeTrue()
        ->and($writer->loopTo)->toBe('writer_node')
        ->and($writer->loopMaxIterations)->toBe(3);
});

test('persisted route plan hydration round-trips a loop through toArray', function () {
    $payload = [
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
                'next' => 'finish_node',
                'loop' => ['to' => 'writer_node', 'max_iterations' => 2],
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'writer_node',
            ],
        ],
    ];

    $roundTripped = HierarchicalRoutePlan::fromArray(
        HierarchicalRoutePlan::fromArray($payload)->toArray()
    );

    expect($roundTripped->node('writer_node')->loopMaxIterations)->toBe(2);
});

test('persisted route plan hydration rejects an unbounded loop', function () {
    expect(fn () => HierarchicalRoutePlan::fromArray([
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
                'next' => 'finish_node',
                'loop' => ['to' => 'writer_node'],
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'writer_node',
            ],
        ],
    ]))->toThrow(SwarmException::class, 'Persisted hierarchical worker node [writer_node] must define [loop.max_iterations] as a positive integer.');
});

test('persisted route plan hydration rejects a loop without an exit next', function () {
    expect(fn () => HierarchicalRoutePlan::fromArray([
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
                'loop' => ['to' => 'writer_node', 'max_iterations' => 3],
            ],
        ],
    ]))->toThrow(SwarmException::class, 'Persisted hierarchical worker node [writer_node] with a [loop] must also define [next] as the loop\'s exit node.');
});

test('persisted route plan hydration rejects a loop to a non-ancestor node', function () {
    expect(fn () => HierarchicalRoutePlan::fromArray([
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
                'next' => 'editor_node',
                'loop' => ['to' => 'editor_node', 'max_iterations' => 3],
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'Edit.',
                'next' => 'finish_node',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ],
    ]))->toThrow(SwarmException::class, 'must loop back to an earlier node on its own path');
});

test('persisted route plan node lookup rejects unknown nodes predictably', function () {
    $plan = HierarchicalRoutePlan::fromArray([
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
            ],
        ],
    ]);

    expect(fn () => $plan->node('missing_node'))
        ->toThrow(SwarmException::class, 'Hierarchical route plan references unknown node [missing_node].');
});

// ---------------------------------------------------------------------------
// Rollup nodes (#289)
// ---------------------------------------------------------------------------

/**
 * A valid chain: writer -> rollup(digests writer) -> consumer(reads the digest)
 * -> finish(reads the digest). Downstream references the rollup, never the
 * digested node, so it materializes cleanly.
 *
 * @param  array<string, mixed>  $overrides  merged into the consumer/finish refs
 * @return array<string, mixed>
 */
function rollupPlan(array $consumerWithOutputs = ['summary' => 'rollup_node'], string $finishFrom = 'consumer_node'): array
{
    return [
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
                'next' => 'rollup_node',
            ],
            'rollup_node' => [
                'type' => 'rollup',
                'agent' => FakeEditor::class,
                'prompt' => 'Digest the draft.',
                'with_outputs' => ['draft' => 'writer_node'],
                'next' => 'consumer_node',
            ],
            'consumer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Use the digest.',
                'with_outputs' => $consumerWithOutputs,
                'next' => 'finish_node',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => $finishFrom,
            ],
        ],
    ];
}

test('persisted route plan hydration accepts a rollup whose digest is read downstream', function () {
    $plan = HierarchicalRoutePlan::fromArray(rollupPlan());

    expect($plan->node('rollup_node'))
        ->toBeInstanceOf(HierarchicalRollupNode::class)
        ->and($plan->node('rollup_node')->digestedNodeIds())->toBe(['writer_node']);
});

test('persisted route plan hydration rejects a downstream node referencing a rolled-up node', function () {
    // consumer reads the raw writer_node, which the rollup already digested.
    expect(fn () => HierarchicalRoutePlan::fromArray(rollupPlan(['leak' => 'writer_node'])))
        ->toThrow(SwarmException::class, 'maps output alias [leak] from [writer_node], which was rolled up by [rollup_node]; reference the rollup node [rollup_node] instead.');
});

test('persisted route plan hydration rejects a finish output_from a rolled-up node', function () {
    expect(fn () => HierarchicalRoutePlan::fromArray(rollupPlan(['summary' => 'rollup_node'], 'writer_node')))
        ->toThrow(SwarmException::class, 'finish node [finish_node] references [writer_node], which was rolled up by [rollup_node]; reference the rollup node [rollup_node] instead.');
});

test('persisted route plan hydration round-trips a rollup through toArray', function () {
    $payload = rollupPlan();

    $roundTripped = HierarchicalRoutePlan::fromArray(
        HierarchicalRoutePlan::fromArray($payload)->toArray()
    );

    $rollup = $roundTripped->node('rollup_node');

    expect($rollup)->toBeInstanceOf(HierarchicalRollupNode::class)
        ->and($rollup->type)->toBe('rollup')
        ->and($rollup->withOutputs)->toBe(['draft' => 'writer_node'])
        ->and($rollup->next)->toBe('consumer_node');
});

test('persisted route plan hydration rejects a rollup with no digested set', function () {
    $payload = rollupPlan();
    unset($payload['nodes']['rollup_node']['with_outputs']);

    expect(fn () => HierarchicalRoutePlan::fromArray($payload))
        ->toThrow(SwarmException::class, 'rollup node [rollup_node] must define [with_outputs] naming the generation it digests.');
});

test('persisted route plan hydration rejects a rollup that defines a loop', function () {
    $payload = rollupPlan();
    $payload['nodes']['rollup_node']['loop'] = ['to' => 'writer_node', 'max_iterations' => 2];

    expect(fn () => HierarchicalRoutePlan::fromArray($payload))
        ->toThrow(SwarmException::class, 'rollup node [rollup_node] cannot define a [loop].');
});
