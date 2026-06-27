<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeChildrenDecided;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeClosed;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeOpened;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalMissingStructuredCoordinatorSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;

/**
 * Streaming execution for Topology::Hierarchical swarms (#285).
 *
 * The coordinator step streams under a synthetic __coordinator__ node on the causal
 * log; once its output is collected the plan is parsed and the plan-walk drives
 * worker nodes from step index 1 with __coordinator__ as the initial parent.
 */
beforeEach(function () {
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
            ],
        ]),
    ]);
    FakeWriter::fake(['writer-out']);
    FakeResearcher::fake(['research-out']);
});

test('a streamed hierarchical swarm emits coordinator node then worker nodes in causal order', function () {
    $stream = FakeHierarchicalStreamSwarm::make()->stream('hierarchical-stream-task');
    $events = iterator_to_array($stream);

    // Coordinator node opened with the synthetic id, role=coordinator, no parent.
    $coordinatorOpened = collect($events)
        ->whereInstanceOf(SwarmNodeOpened::class)
        ->firstWhere('role', 'coordinator');

    expect($coordinatorOpened)->not->toBeNull();
    expect($coordinatorOpened->id)->toBe('__coordinator__');
    expect($coordinatorOpened->nodeId)->toBe('__coordinator__');
    expect($coordinatorOpened->parentNodeId)->toBeNull();

    // A worker node is opened after the coordinator node.
    $workerOpened = collect($events)
        ->whereInstanceOf(SwarmNodeOpened::class)
        ->firstWhere('role', 'worker');

    expect($workerOpened)->not->toBeNull();
    expect($workerOpened->nodeId)->toBe('writer_node');
    expect($workerOpened->parentNodeId)->toBe('__coordinator__');

    // The coordinator's children-decided event names the plan start node.
    $coordinatorDecided = collect($events)
        ->whereInstanceOf(SwarmNodeChildrenDecided::class)
        ->firstWhere('nodeId', '__coordinator__');

    expect($coordinatorDecided)->not->toBeNull();
    expect($coordinatorDecided->childNodeIds)->toBe(['writer_node']);

    // Causal order: coordinator opened before worker opened.
    $coordinatorOpenedIndex = array_search($coordinatorOpened, $events, true);
    $workerOpenedIndex = array_search($workerOpened, $events, true);
    expect($coordinatorOpenedIndex)->toBeLessThan($workerOpenedIndex);
});

test('streamed hierarchical swarm emits no text deltas for __coordinator__ because coordinator runs synchronously', function () {
    // laravel/ai does not support streaming HasStructuredOutput agents, so the
    // coordinator always runs via prompt() and emits only structural events.
    // This test verifies no SwarmTextDelta events carry the __coordinator__ node id.
    $stream = FakeHierarchicalStreamSwarm::make()->stream('hierarchical-stream-task');
    $events = iterator_to_array($stream);

    $coordinatorDeltas = collect($events)
        ->whereInstanceOf(SwarmTextDelta::class)
        ->filter(fn (SwarmTextDelta $e): bool => $e->nodeId === '__coordinator__');

    expect($coordinatorDeltas)->toBeEmpty();

    // The coordinator's output IS captured on SwarmStepEnd.
    $coordinatorStepEnd = collect($events)
        ->whereInstanceOf(SwarmStepEnd::class)
        ->firstWhere('nodeId', '__coordinator__');

    expect($coordinatorStepEnd)->not->toBeNull();
    expect($coordinatorStepEnd->stepIndex)->toBe(0);
});

test('streamed hierarchical swarm step index starts at 0 for coordinator and 1 for first worker', function () {
    $stream = FakeHierarchicalStreamSwarm::make()->stream('hierarchical-stream-task');
    $events = iterator_to_array($stream);

    $stepStarts = collect($events)->whereInstanceOf(SwarmStepStart::class)->values();

    // Coordinator is step 0.
    expect($stepStarts->first()->stepIndex)->toBe(0);
    expect($stepStarts->first()->nodeId)->toBe('__coordinator__');
    expect($stepStarts->first()->agentClass)->toBe(FakeHierarchicalCoordinator::class);

    // First worker is step 1.
    expect($stepStarts->get(1)->stepIndex)->toBe(1);
    expect($stepStarts->get(1)->nodeId)->toBe('writer_node');
    expect($stepStarts->get(1)->agentClass)->toBe(FakeWriter::class);
});

test('streamed hierarchical swarm emits node.closed for __coordinator__ after children-decided', function () {
    $stream = FakeHierarchicalStreamSwarm::make()->stream('hierarchical-stream-task');
    $events = iterator_to_array($stream);

    $coordinatorClosed = collect($events)
        ->whereInstanceOf(SwarmNodeClosed::class)
        ->firstWhere('nodeId', '__coordinator__');

    expect($coordinatorClosed)->not->toBeNull();

    // Causal order: children-decided precedes closed.
    $decided = collect($events)
        ->whereInstanceOf(SwarmNodeChildrenDecided::class)
        ->firstWhere('nodeId', '__coordinator__');

    $decidedIndex = array_search($decided, $events, true);
    $closedIndex = array_search($coordinatorClosed, $events, true);
    expect($decidedIndex)->toBeLessThan($closedIndex);
});

test('streamed hierarchical swarm fails loud when coordinator lacks HasStructuredOutput', function () {
    expect(fn () => FakeHierarchicalMissingStructuredCoordinatorSwarm::make()->stream('task'))
        ->toThrow(SwarmException::class, 'Laravel AI structured output');
});
