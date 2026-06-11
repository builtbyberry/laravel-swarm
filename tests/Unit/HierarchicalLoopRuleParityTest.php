<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

/**
 * Locks the no-drift invariant for the shared LoopRuleValidator: the same
 * malformed-loop plan must be rejected by the SAME rule whether it is freshly
 * built (HierarchicalRoutePlanner::fromStaticPlan) or rehydrated from persisted
 * state (HierarchicalRoutePlan::fromArray). Only the "Persisted " prefix differs.
 */
dataset('malformed loop plans', [
    'loop target is not a worker' => [
        'plan' => [
            'start_at' => 'writer_node',
            'nodes' => [
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'Write.',
                    'next' => 'parallel_node',
                ],
                'parallel_node' => [
                    'type' => 'parallel',
                    'branches' => ['research_node'],
                    'next' => 'editor_node',
                ],
                'research_node' => [
                    'type' => 'worker',
                    'agent' => FakeResearcher::class,
                    'prompt' => 'Research.',
                ],
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => FakeEditor::class,
                    'prompt' => 'Edit.',
                    'next' => 'finish_node',
                    'loop' => ['to' => 'parallel_node', 'max_iterations' => 3],
                ],
                'finish_node' => ['type' => 'finish', 'output_from' => 'editor_node'],
            ],
        ],
        'message' => 'worker node [editor_node] may only loop back to a worker node, not [parallel_node].',
    ],
    'loop target does not reach the looping node' => [
        'plan' => [
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
                'finish_node' => ['type' => 'finish', 'output_from' => 'editor_node'],
            ],
        ],
        'message' => 'worker node [writer_node] must loop back to an earlier node on its own path; [editor_node] does not reach [writer_node].',
    ],
    'nested loop escapes the enclosing loop body' => [
        'plan' => [
            'start_at' => 'writer_node',
            'nodes' => [
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'Write.',
                    'next' => 'research_node',
                ],
                'research_node' => [
                    'type' => 'worker',
                    'agent' => FakeResearcher::class,
                    'prompt' => 'Research.',
                    'next' => 'editor_node',
                    // Inner loop targets writer_node, escaping the outer loop body
                    // (which starts at research_node).
                    'loop' => ['to' => 'writer_node', 'max_iterations' => 2],
                ],
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => FakeEditor::class,
                    'prompt' => 'Edit.',
                    'next' => 'finish_node',
                    'loop' => ['to' => 'research_node', 'max_iterations' => 3],
                ],
                'finish_node' => ['type' => 'finish', 'output_from' => 'editor_node'],
            ],
        ],
        'message' => 'worker node [research_node] loops back to [writer_node], which escapes the enclosing loop of [editor_node]; nested loops must be fully contained.',
    ],
]);

test('build path rejects malformed loops with the unprefixed message', function (array $plan, string $message) {
    $planner = new HierarchicalRoutePlanner;

    expect(fn () => $planner->fromStaticPlan(
        [new FakeWriter, new FakeEditor, new FakeResearcher],
        $plan,
        'TestSwarm',
    ))->toThrow(SwarmException::class, 'Hierarchical '.$message);
})->with('malformed loop plans');

test('rehydrate path rejects the same malformed loops with the Persisted prefix', function (array $plan, string $message) {
    expect(fn () => HierarchicalRoutePlan::fromArray($plan))
        ->toThrow(SwarmException::class, 'Persisted hierarchical '.$message);
})->with('malformed loop plans');
