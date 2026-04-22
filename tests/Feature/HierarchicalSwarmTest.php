<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalCoordinatorOnlySwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalEmptySwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalLimitedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalMissingRouteSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalMultiRouteSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalSingleRouteSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalUnknownRouteSwarm;

beforeEach(function () {
    FakeResearcher::fake(['coordinator-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('hierarchical swarm can complete with only the coordinator output', function () {
    $response = FakeHierarchicalCoordinatorOnlySwarm::make()->run('hierarchical-task');

    expect($response->output)->toBe('coordinator-out');
    expect($response->steps)->toHaveCount(1);
    expect($response->metadata['coordinator_agent_class'])->toBe(FakeResearcher::class);
    expect($response->metadata['routed_agent_classes'])->toBe([]);
});

test('hierarchical swarm routes to a single worker with a custom input', function () {
    $response = FakeHierarchicalSingleRouteSwarm::make()->run('hierarchical-task');

    FakeResearcher::assertPrompted('hierarchical-task');
    FakeWriter::assertPrompted('writer-task: coordinator-out');

    expect($response->output)->toBe('writer-out');
    expect($response->steps)->toHaveCount(2);
    expect($response->steps[1]->metadata['branch'])->toBe('writer');
    expect($response->metadata['routed_agent_classes'])->toBe([FakeWriter::class]);
});

test('hierarchical swarm routes multiple workers in the specified order', function () {
    $response = FakeHierarchicalMultiRouteSwarm::make()->run('hierarchical-task');

    FakeEditor::assertPrompted('editor-task: coordinator-out');
    FakeWriter::assertPrompted('writer-task: coordinator-out');

    expect($response->output)->toBe('writer-out');
    expect(array_map(fn ($step) => $step->agentClass, $response->steps))->toBe([
        FakeResearcher::class,
        FakeEditor::class,
        FakeWriter::class,
    ]);
    expect($response->metadata['executed_steps'])->toBe(3);
});

test('hierarchical swarm requires a route method', function () {
    expect(fn () => FakeHierarchicalMissingRouteSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical swarms must define a route() method.');
});

test('hierarchical swarm names unknown routed classes explicitly', function () {
    expect(fn () => FakeHierarchicalUnknownRouteSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical route references unknown agent class [App\\Ai\\Agents\\Foo]. Verify it is returned from agents().');
});

test('hierarchical swarm enforces max agent step limits across coordinator and workers', function () {
    $response = FakeHierarchicalLimitedSwarm::make()->run('hierarchical-task');

    expect($response->steps)->toHaveCount(2);
    expect(array_map(fn ($step) => $step->agentClass, $response->steps))->toBe([
        FakeResearcher::class,
        FakeWriter::class,
    ]);
    expect($response->metadata['routed_agent_classes'])->toBe([FakeWriter::class]);
});

test('hierarchical swarm requires at least one agent', function () {
    expect(fn () => FakeHierarchicalEmptySwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical swarms must define at least one agent.');
});

test('hierarchical swarm persists routed step history metadata', function () {
    $response = FakeHierarchicalMultiRouteSwarm::make()->run('hierarchical-task');
    $history = app(RunHistoryStore::class)->find($response->metadata['run_id']);

    expect($history['status'])->toBe('completed');
    expect($history['steps'])->toHaveCount(3);
    expect($history['metadata']['coordinator_agent_class'])->toBe(FakeResearcher::class);
    expect($history['metadata']['routed_agent_classes'])->toBe([FakeEditor::class, FakeWriter::class]);
});
