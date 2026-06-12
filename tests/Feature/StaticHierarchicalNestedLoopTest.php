<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakePlanner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeReviewer;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalNestedLoopSwarm;

beforeEach(function () {
    FakeResearcher::fake(array_fill(0, 40, 'research-out'));
    FakeWriter::fake(array_fill(0, 40, 'writer-out'));
    FakeEditor::fake(array_fill(0, 40, 'editor-out'));
    FakeReviewer::fake(array_fill(0, 40, 'reviewer-out'));
    FakePlanner::fake(array_fill(0, 40, 'planner-out'));
});

test('sync nested loop runs the inner loop to full count on every outer pass', function () {
    $response = FakeStaticHierarchicalNestedLoopSwarm::make()->run('nested-task');

    $executed = $response->metadata['executed_node_ids'];

    // Inner loop body (writer + editor) runs 3× per outer pass, across 2 outer
    // passes = 6 each; reviewer runs once per outer pass = 2.
    $writerRuns = array_filter($executed, static fn (string $id): bool => $id === 'inner_body');
    $editorRuns = array_filter($executed, static fn (string $id): bool => $id === 'inner_loop');
    $reviewerRuns = array_filter($executed, static fn (string $id): bool => $id === 'outer_loop');

    expect($writerRuns)->toHaveCount(6)
        ->and($editorRuns)->toHaveCount(6)
        ->and($reviewerRuns)->toHaveCount(2);

    // The exact interleaving: each outer pass replays the full inner loop.
    expect($executed)->toBe([
        'inner_body', 'inner_loop',  // inner it1
        'inner_body', 'inner_loop',  // inner it2
        'inner_body', 'inner_loop',  // inner it3 -> falls to outer
        'outer_loop',                // outer it1 -> loops back, resets inner
        'inner_body', 'inner_loop',  // inner it1 (reset worked)
        'inner_body', 'inner_loop',  // inner it2
        'inner_body', 'inner_loop',  // inner it3
        'outer_loop',                // outer it2 -> finish
        'finish',
    ]);
});

test('sync triple nesting executes the product of bounds', function () {
    $swarm = new #[Topology(TopologyEnum::StaticHierarchical)] #[MaxAgentSteps(60)] class implements HasRoutePlan, Swarm
    {
        use Runnable;

        public function agents(): array
        {
            return [new FakePlanner, new FakeWriter, new FakeEditor, new FakeReviewer, new FakeResearcher];
        }

        public function plan(): array
        {
            // Spine a->b->c; inner(researcher) loops to c (max 2),
            // mid(reviewer) loops to b (max 2), out... reuse is not allowed, so
            // out is a sixth distinct node. We only have 5 agents, so the outer
            // looping node reuses none — instead the two-level-deep nest uses
            // inner(c) and mid(b). Expected counts: a=1, b=2, c=4, inner=4, mid=2.
            return [
                'start_at' => 'a',
                'nodes' => [
                    'a' => ['type' => 'worker', 'agent' => FakePlanner::class, 'prompt' => 'a', 'next' => 'b'],
                    'b' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'b', 'next' => 'c'],
                    'c' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'c', 'next' => 'inner'],
                    'inner' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'i', 'next' => 'mid', 'loop' => ['to' => 'c', 'max_iterations' => 2]],
                    'mid' => ['type' => 'worker', 'agent' => FakeReviewer::class, 'prompt' => 'm', 'next' => 'finish', 'loop' => ['to' => 'b', 'max_iterations' => 2]],
                    'finish' => ['type' => 'finish', 'output_from' => 'mid'],
                ],
            ];
        }
    };

    $response = $swarm->run('triple-task');
    $executed = $response->metadata['executed_node_ids'];

    $count = fn (string $id): int => count(array_filter($executed, static fn (string $n): bool => $n === $id));

    // Product of bounds: mid loops b (×2), inner loops c (×2).
    // a=1, b=2, c=4, inner=4, mid=2.
    expect($count('a'))->toBe(1)
        ->and($count('b'))->toBe(2)
        ->and($count('c'))->toBe(4)
        ->and($count('inner'))->toBe(4)
        ->and($count('mid'))->toBe(2);
});

test('an over-budget nested plan is rejected before any worker runs', function () {
    $swarm = new #[Topology(TopologyEnum::StaticHierarchical)] #[MaxAgentSteps(12)] class implements HasRoutePlan, Swarm
    {
        use Runnable;

        public function agents(): array
        {
            return [new FakePlanner, new FakeWriter, new FakeEditor, new FakeResearcher, new FakeReviewer];
        }

        public function plan(): array
        {
            // Product-of-bounds total is 13 (> 12) — must be rejected up front.
            return [
                'start_at' => 'a',
                'nodes' => [
                    'a' => ['type' => 'worker', 'agent' => FakePlanner::class, 'prompt' => 'a', 'next' => 'b'],
                    'b' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'b', 'next' => 'c'],
                    'c' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'c', 'next' => 'inner'],
                    'inner' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'i', 'next' => 'mid', 'loop' => ['to' => 'c', 'max_iterations' => 2]],
                    'mid' => ['type' => 'worker', 'agent' => FakeReviewer::class, 'prompt' => 'm', 'next' => 'finish', 'loop' => ['to' => 'b', 'max_iterations' => 2]],
                    'finish' => ['type' => 'finish', 'output_from' => 'mid'],
                ],
            ];
        }
    };

    expect(fn () => $swarm->run('over-budget'))
        ->toThrow(SwarmException::class, 'requires 13 agent executions but the swarm allows 12');

    FakePlanner::assertNeverPrompted();
    FakeWriter::assertNeverPrompted();
});

test('a nested plan exactly at budget is accepted with the product-of-bounds counts', function () {
    $swarm = new #[Topology(TopologyEnum::StaticHierarchical)] #[MaxAgentSteps(13)] class implements HasRoutePlan, Swarm
    {
        use Runnable;

        public function agents(): array
        {
            return [new FakePlanner, new FakeWriter, new FakeEditor, new FakeResearcher, new FakeReviewer];
        }

        public function plan(): array
        {
            return [
                'start_at' => 'a',
                'nodes' => [
                    'a' => ['type' => 'worker', 'agent' => FakePlanner::class, 'prompt' => 'a', 'next' => 'b'],
                    'b' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'b', 'next' => 'c'],
                    'c' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'c', 'next' => 'inner'],
                    'inner' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'i', 'next' => 'mid', 'loop' => ['to' => 'c', 'max_iterations' => 2]],
                    'mid' => ['type' => 'worker', 'agent' => FakeReviewer::class, 'prompt' => 'm', 'next' => 'finish', 'loop' => ['to' => 'b', 'max_iterations' => 2]],
                    'finish' => ['type' => 'finish', 'output_from' => 'mid'],
                ],
            ];
        }
    };

    $response = $swarm->run('at-budget');
    $count = fn (string $id): int => count(array_filter($response->metadata['executed_node_ids'], static fn (string $n): bool => $n === $id));

    expect($count('a'))->toBe(1)
        ->and($count('b'))->toBe(2)
        ->and($count('c'))->toBe(4)
        ->and($count('inner'))->toBe(4)
        ->and($count('mid'))->toBe(2);
});

test('sync inner loop with its own next exit re-runs fully under an outer loop', function () {
    $swarm = new #[Topology(TopologyEnum::StaticHierarchical)] #[MaxAgentSteps(40)] class implements HasRoutePlan, Swarm
    {
        use Runnable;

        public function agents(): array
        {
            return [new FakePlanner, new FakeWriter, new FakeEditor, new FakeReviewer];
        }

        public function plan(): array
        {
            // a(planner) -> b(writer) -> inner(editor, loop to b, 2)
            //   -> outer(reviewer, loop to a, 2) -> finish
            // inner's exit `next` is the outer node; outer resets the inner loop.
            // Counts: a=2, b=4, inner=4, outer=2.
            return [
                'start_at' => 'a',
                'nodes' => [
                    'a' => ['type' => 'worker', 'agent' => FakePlanner::class, 'prompt' => 'a', 'next' => 'b'],
                    'b' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'b', 'next' => 'inner'],
                    'inner' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'i', 'next' => 'outer', 'loop' => ['to' => 'b', 'max_iterations' => 2]],
                    'outer' => ['type' => 'worker', 'agent' => FakeReviewer::class, 'prompt' => 'o', 'next' => 'finish', 'loop' => ['to' => 'a', 'max_iterations' => 2]],
                    'finish' => ['type' => 'finish', 'output_from' => 'outer'],
                ],
            ];
        }
    };

    $response = $swarm->run('inner-exit-task');
    $executed = $response->metadata['executed_node_ids'];
    $count = fn (string $id): int => count(array_filter($executed, static fn (string $n): bool => $n === $id));

    expect($count('a'))->toBe(2)
        ->and($count('b'))->toBe(4)
        ->and($count('inner'))->toBe(4)
        ->and($count('outer'))->toBe(2);
});
