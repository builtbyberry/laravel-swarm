<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SerializationBoundaryParallelBranchOne;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SerializationBoundaryParallelBranchTwo;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\UnresolvableParallelAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SerializationBoundaryHierarchicalParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SerializationBoundaryParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\UnresolvableParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;

pest()->group('process-concurrency');

test('parallel swarm crosses the real process concurrency driver without agent instance state', function () {
    $response = SerializationBoundaryParallelSwarm::make()->run('shared-task');

    expect($response->steps)->toHaveCount(2)
        ->and((string) $response)->toContain('serialization-boundary:shared-task');
});

test('hierarchical swarm executes parallel group and join under the real process concurrency driver', function () {
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => SerializationBoundaryParallelBranchOne::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => SerializationBoundaryParallelBranchTwo::class,
                'prompt' => 'editor-branch',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ]),
    ]);

    $response = SerializationBoundaryHierarchicalParallelSwarm::make()->run('hierarchical-task');

    expect($response->output)->toContain('serialization-boundary:editor-branch');
    expect($response->metadata['executed_node_ids'])->toBe(['parallel_node', 'writer_node', 'editor_node', 'finish_node']);
    expect($response->metadata['parallel_groups'])->toBe([
        ['node_id' => 'parallel_node', 'branches' => ['writer_node', 'editor_node']],
    ]);

    $parallelSteps = array_values(array_filter(
        $response->steps,
        fn ($step) => ($step->metadata['parent_parallel_node_id'] ?? null) === 'parallel_node'
    ));

    expect($parallelSteps)->toHaveCount(2);

    foreach ($parallelSteps as $step) {
        expect($step->metadata['parent_parallel_node_id'])->toBe('parallel_node');
    }
});

test('parallel swarm agents must be container resolvable before process concurrency dispatch', function () {
    expect(fn () => UnresolvableParallelSwarm::make()->run('shared-task'))
        ->toThrow(SwarmException::class, UnresolvableParallelSwarm::class.': parallel agent ['.UnresolvableParallelAgent::class.'] must be container-resolvable because Laravel Concurrency serializes worker callbacks.');
});
