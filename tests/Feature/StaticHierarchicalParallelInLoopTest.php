<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeReviewer;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalParallelInLoopSwarm;

beforeEach(function () {
    FakeEditor::fake(array_fill(0, 20, 'gather-out'));
    FakeResearcher::fake(array_fill(0, 20, 'research-out'));
    FakeWriter::fake(array_fill(0, 20, 'write-out'));
    FakeReviewer::fake(array_fill(0, 20, 'review-out'));
});

test('sync parallel-in-loop re-runs the fan-out on every iteration', function () {
    $response = FakeStaticHierarchicalParallelInLoopSwarm::make()->run('par-loop-task');

    $executed = $response->metadata['executed_node_ids'];
    $count = fn (string $id): int => count(array_filter($executed, static fn (string $n): bool => $n === $id));

    // Three loop iterations; each runs gather + both branches + join once.
    expect($count('gather'))->toBe(3)
        ->and($count('branch_research'))->toBe(3)
        ->and($count('branch_write'))->toBe(3)
        ->and($count('join'))->toBe(3)
        ->and($count('fan_out'))->toBe(3);

    expect($response->metadata['parallel_groups'])->toHaveCount(3);
});

test('sync parallel-in-loop branch steps carry the enclosing loop_iteration', function () {
    $response = FakeStaticHierarchicalParallelInLoopSwarm::make()->run('par-loop-meta');

    $branchSteps = array_values(array_filter(
        $response->steps,
        static fn ($step) => in_array($step->metadata['node_id'] ?? null, ['branch_research', 'branch_write'], true),
    ));

    // 2 branches × 3 iterations = 6 branch steps; each must carry the loop
    // iteration of the pass it ran in (1,1,2,2,3,3 once grouped by node).
    expect($branchSteps)->toHaveCount(6);

    $researchIterations = array_values(array_map(
        static fn ($step) => $step->metadata['loop_iteration'] ?? null,
        array_filter($branchSteps, static fn ($s) => ($s->metadata['node_id'] ?? null) === 'branch_research'),
    ));

    expect($researchIterations)->toBe([1, 2, 3]);

    foreach ($branchSteps as $step) {
        expect($step->metadata)->toHaveKey('loop_iteration');
    }
});
