<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalWorkerNode;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;

beforeEach(function () {
    $this->planner = new HierarchicalRoutePlanner;
    $this->workers = [new FakeWriter, new FakeEditor];
});

test('planner accepts a bounded loop back-edge', function () {
    $plan = $this->planner->fromStaticPlan($this->workers, [
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
                'next' => 'finish_node',
                'loop' => ['to' => 'writer_node', 'max_iterations' => 4],
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ],
    ], 'TestSwarm');

    /** @var HierarchicalWorkerNode $editor */
    $editor = $plan->node('editor_node');

    expect($editor->hasLoop())->toBeTrue()
        ->and($editor->loopTo)->toBe('writer_node')
        ->and($editor->loopMaxIterations)->toBe(4);
});

test('planner inflates the worst-case worker count by the loop bound', function () {
    $plan = $this->planner->fromStaticPlan($this->workers, [
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
                'next' => 'finish_node',
                'loop' => ['to' => 'writer_node', 'max_iterations' => 3],
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ],
    ], 'TestSwarm');

    // 2 workers on the first pass + 2 replays * 2 workers in the loop body.
    expect($plan->reachableWorkerCount())->toBe(6);
});

test('planner rejects an unbounded loop', function () {
    expect(fn () => $this->planner->fromStaticPlan($this->workers, [
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Write.',
                'next' => 'finish_node',
                'loop' => ['to' => 'writer_node', 'max_iterations' => 0],
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'writer_node',
            ],
        ],
    ], 'TestSwarm'))->toThrow(SwarmException::class, 'must define [loop.max_iterations] as a positive integer to bound the loop. Unbounded loops are not supported.');
});

test('planner still rejects a plain next cycle as unbounded', function () {
    expect(fn () => $this->planner->fromStaticPlan($this->workers, [
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
                'next' => 'writer_node',
            ],
        ],
    ], 'TestSwarm'))->toThrow(SwarmException::class, 'Hierarchical route plans must be acyclic. Loops are not supported in this release.');
});

test('planner rejects a forward loop jump that is not a back-edge', function () {
    expect(fn () => $this->planner->fromStaticPlan($this->workers, [
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
    ], 'TestSwarm'))->toThrow(SwarmException::class, 'must loop back to an earlier node on its own path');
});
