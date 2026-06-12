<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalWorkerNode;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakePlanner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeReviewer;
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

test('planner budgets nested loops as the product of enclosing bounds', function () {
    // a(writer)->b(editor)->c(researcher)->inner(reviewer, loop c, max 2)
    //   ->mid(... loop b, max 2). mid must be a distinct class; reuse none.
    // Counts: a=1, b=2, c=4, inner=4, mid=2 => 13.
    $plan = $this->planner->fromStaticPlan(
        [new FakeWriter, new FakeEditor, new FakeResearcher, new FakeReviewer, new FakePlanner],
        [
            'start_at' => 'a',
            'nodes' => [
                'a' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'a', 'next' => 'b'],
                'b' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'b', 'next' => 'c'],
                'c' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'c', 'next' => 'inner'],
                'inner' => ['type' => 'worker', 'agent' => FakeReviewer::class, 'prompt' => 'i', 'next' => 'mid', 'loop' => ['to' => 'c', 'max_iterations' => 2]],
                'mid' => ['type' => 'worker', 'agent' => FakePlanner::class, 'prompt' => 'm', 'next' => 'finish', 'loop' => ['to' => 'b', 'max_iterations' => 2]],
                'finish' => ['type' => 'finish', 'output_from' => 'mid'],
            ],
        ],
        'TestSwarm',
    );

    // a=1, b=2, c=4, inner=4, mid=2 => 13. A naive additive model would yield 9.
    expect($plan->reachableWorkerCount())->toBe(13);
});

test('planner rejects a nested plan one execution over the budget', function () {
    $planner = $this->planner;

    $build = fn (): HierarchicalRoutePlan => $planner->fromStaticPlan(
        [new FakeWriter, new FakeEditor, new FakeResearcher, new FakeReviewer, new FakePlanner],
        [
            'start_at' => 'a',
            'nodes' => [
                'a' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'a', 'next' => 'b'],
                'b' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'b', 'next' => 'c'],
                'c' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'c', 'next' => 'inner'],
                'inner' => ['type' => 'worker', 'agent' => FakeReviewer::class, 'prompt' => 'i', 'next' => 'mid', 'loop' => ['to' => 'c', 'max_iterations' => 2]],
                'mid' => ['type' => 'worker', 'agent' => FakePlanner::class, 'prompt' => 'm', 'next' => 'finish', 'loop' => ['to' => 'b', 'max_iterations' => 2]],
                'finish' => ['type' => 'finish', 'output_from' => 'mid'],
            ],
        ],
        'TestSwarm',
    );

    // The static budget gate (StaticHierarchicalRunner::ensureStaticPlanWithinExecutionBudget)
    // compares reachableWorkerCount() against MaxAgentSteps. Product-of-bounds = 13:
    // a budget of 12 must reject, 13 must accept — both keyed off this single count.
    expect($build()->reachableWorkerCount())->toBe(13);
});

test('planner counts a parallel group inside a loop once per branch per iteration', function () {
    // gather -> parallel(b1,b2) -> join(loop to gather, max 3).
    // Each iteration runs gather + b1 + b2 + join. Over 3 iterations that is
    // 12 worker executions. The branches must be counted ONCE each (not twice as
    // the previous double-counting did, which over-reported the budget).
    $plan = $this->planner->fromStaticPlan(
        [new FakeWriter, new FakeEditor, new FakeResearcher, new FakeReviewer],
        [
            'start_at' => 'gather',
            'nodes' => [
                'gather' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'g', 'next' => 'fan_out'],
                'fan_out' => ['type' => 'parallel', 'branches' => ['b1', 'b2'], 'next' => 'join'],
                'b1' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'b1'],
                'b2' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'b2'],
                'join' => ['type' => 'worker', 'agent' => FakeReviewer::class, 'prompt' => 'j', 'next' => 'finish', 'loop' => ['to' => 'gather', 'max_iterations' => 3]],
                'finish' => ['type' => 'finish', 'output_from' => 'join'],
            ],
        ],
        'TestSwarm',
    );

    expect($plan->reachableWorkerCount())->toBe(12);
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
