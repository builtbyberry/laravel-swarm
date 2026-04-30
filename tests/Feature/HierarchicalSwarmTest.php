<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalCoordinatorOnlySwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalDuplicateWorkerSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalEmptySwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalLimitedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalMissingStructuredCoordinatorSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalMultiRouteSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalSingleRouteSwarm;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Facades\Event;

function hierarchicalPlan(string $startAt, array $nodes): array
{
    return [
        'start_at' => $startAt,
        'nodes' => $nodes,
    ];
}

beforeEach(function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
            ],
        ]),
    ]);
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('hierarchical swarm executes a valid single-worker plan', function () {
    $response = FakeHierarchicalSingleRouteSwarm::make()->run('hierarchical-task');

    expect($response->output)->toBe('writer-out');
    expect($response->steps)->toHaveCount(2);
    expect($response->metadata['coordinator_agent_class'])->toBe(FakeHierarchicalCoordinator::class);
    expect($response->metadata['route_plan_start'])->toBe('writer_node');
    expect($response->metadata['executed_node_ids'])->toBe(['writer_node']);
    expect($response->metadata['executed_agent_classes'])->toBe([FakeWriter::class]);

    FakeWriter::assertPrompted('writer-task');
});

test('hierarchical swarm executes a valid sequential worker chain', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'next' => 'editor_node',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-task',
                'with_outputs' => ['draft' => 'writer_node'],
            ],
        ]),
    ]);

    $response = FakeHierarchicalFullSwarm::make()->run('hierarchical-task');

    expect($response->output)->toBe('editor-out');
    expect(array_map(fn ($step) => $step->agentClass, $response->steps))->toBe([
        FakeHierarchicalCoordinator::class,
        FakeWriter::class,
        FakeEditor::class,
    ]);
    expect($response->metadata['executed_node_ids'])->toBe(['writer_node', 'editor_node']);
    expect($response->metadata['executed_agent_classes'])->toBe([FakeWriter::class, FakeEditor::class]);

    FakeWriter::assertPrompted('writer-task');
    FakeEditor::assertPrompted(<<<'PROMPT'
editor-task

Named outputs:
[draft]
writer-out
PROMPT);
});

test('hierarchical swarm executes a valid parallel group followed by a join', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ]),
    ]);

    $response = FakeHierarchicalFullSwarm::make()->run('hierarchical-task');

    expect($response->output)->toBe('editor-out');
    expect($response->metadata['executed_node_ids'])->toBe(['parallel_node', 'writer_node', 'editor_node', 'finish_node']);
    expect($response->metadata['parallel_groups'])->toBe([
        ['node_id' => 'parallel_node', 'branches' => ['writer_node', 'editor_node']],
    ]);
    expect($response->steps[1]->metadata['parent_parallel_node_id'])->toBe('parallel_node');
    expect($response->steps[2]->metadata['parent_parallel_node_id'])->toBe('parallel_node');
});

test('hierarchical swarm fails when parallel branch results are missing', function () {
    app()->instance(ConcurrencyManager::class, new class(app()) extends ConcurrencyManager
    {
        public function driver($name = null): Driver
        {
            return new class implements Driver
            {
                public function run(Closure|array $tasks): array
                {
                    return [];
                }

                public function defer(Closure|array $tasks): DeferredCallback
                {
                    throw new RuntimeException('Not supported.');
                }
            };
        }
    });

    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalFullSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, FakeHierarchicalFullSwarm::class.': hierarchical parallel execution did not return a result for branch node [writer_node].');
});

test('hierarchical swarm can finish without worker execution', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('finish_node', [
            'finish_node' => [
                'type' => 'finish',
                'output' => 'done-without-workers',
            ],
        ]),
    ]);

    $response = FakeHierarchicalCoordinatorOnlySwarm::make()->run('hierarchical-task');

    expect($response->output)->toBe('done-without-workers');
    expect($response->steps)->toHaveCount(1);
    expect($response->metadata['executed_node_ids'])->toBe(['finish_node']);
    expect($response->metadata['executed_agent_classes'])->toBe([]);
});

test('hierarchical swarm rejects plans that route the coordinator to itself', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('coordinator_node', [
            'coordinator_node' => [
                'type' => 'worker',
                'agent' => FakeHierarchicalCoordinator::class,
                'prompt' => 'should-not-run',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalSingleRouteSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical route node [coordinator_node] cannot route the coordinator ['.FakeHierarchicalCoordinator::class.'] as a worker.');
});

test('hierarchical swarm names unknown worker classes explicitly', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('unknown_node', [
            'unknown_node' => [
                'type' => 'worker',
                'agent' => 'App\\Ai\\Agents\\Foo',
                'prompt' => 'should-not-run',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalSingleRouteSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical route node [unknown_node] references unknown worker agent class [App\\Ai\\Agents\\Foo]. Verify it is returned from agents().');
});

test('hierarchical swarm rejects unknown node references', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'next' => 'missing_node',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalSingleRouteSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical route node [writer_node] references unknown node [missing_node].');
});

test('hierarchical swarm rejects cyclic plans', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'next' => 'editor_node',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-task',
                'next' => 'writer_node',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalMultiRouteSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical route plans must be acyclic. Loops are not supported in this release.');
});

test('hierarchical swarm rejects unreachable nodes', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
            ],
            'orphan_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'orphan-task',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalMultiRouteSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical route plan contains unreachable node [orphan_node].');
});

test('hierarchical swarm rejects invalid finish node shapes', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('finish_node', [
            'finish_node' => [
                'type' => 'finish',
                'output' => 'done',
                'output_from' => 'writer_node',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalCoordinatorOnlySwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical finish node [finish_node] must define exactly one of [output] or [output_from].');
});

test('hierarchical swarm requires a structured-output coordinator', function () {
    Event::fake();

    expect(fn () => FakeHierarchicalMissingStructuredCoordinatorSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical coordinators must implement Laravel AI structured output.');

    FakeResearcher::assertNeverPrompted();
    Event::assertNotDispatched(SwarmStepStarted::class);
    Event::assertNotDispatched(SwarmStepCompleted::class);
});

test('hierarchical swarm enforces max agent step limits across coordinator and workers', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'next' => 'editor_node',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-task',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalLimitedSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, FakeHierarchicalLimitedSwarm::class.": hierarchical route plan requires 3 agent executions but the swarm allows 2. Increase #[MaxAgentSteps] or reduce the plan's worker nodes.");

    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
});

test('hierarchical swarm rejects over-budget parallel groups before worker execution', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalLimitedSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, FakeHierarchicalLimitedSwarm::class.": hierarchical route plan requires 3 agent executions but the swarm allows 2. Increase #[MaxAgentSteps] or reduce the plan's worker nodes.");

    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
});

test('hierarchical swarm requires parallel nodes to define next during validation', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalFullSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, FakeHierarchicalFullSwarm::class.': parallel node [parallel_node] must define `next` in v1. Every parallel group must join into a subsequent node before the workflow can finish.');

    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
});

test('parallel nodes missing next fail validation before budget checks', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalLimitedSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, FakeHierarchicalLimitedSwarm::class.': parallel node [parallel_node] must define `next` in v1. Every parallel group must join into a subsequent node before the workflow can finish.');

    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
});

test('unreachable nodes fail validation before budget checks', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
            ],
            'orphan_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'orphan-task',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalLimitedSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical route plan contains unreachable node [orphan_node].');

    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
});

test('hierarchical swarm requires at least one agent', function () {
    expect(fn () => FakeHierarchicalEmptySwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'FakeHierarchicalEmptySwarm: swarm has no agents. Add at least one agent to agents().');
});

test('hierarchical swarm rejects duplicate worker classes', function () {
    expect(fn () => FakeHierarchicalDuplicateWorkerSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, FakeHierarchicalDuplicateWorkerSwarm::class.': agents() contains duplicate agent class '.FakeWriter::class.'. Hierarchical worker classes must be unique.');
});

test('hierarchical swarm persists node ids and branch metadata to history', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
                'metadata' => ['role' => 'draft'],
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
                'metadata' => ['role' => 'review'],
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ]),
    ]);

    $response = FakeHierarchicalMultiRouteSwarm::make()->run('hierarchical-task');
    $history = app(RunHistoryStore::class)->find($response->metadata['run_id']);

    expect($history['metadata']['route_plan_start'])->toBe('parallel_node');
    expect($history['metadata']['executed_node_ids'])->toBe(['parallel_node', 'writer_node', 'editor_node', 'finish_node']);
    expect($history['steps'][1]['metadata']['node_id'])->toBe('writer_node');
    expect($history['steps'][1]['metadata']['parent_parallel_node_id'])->toBe('parallel_node');
    expect($history['steps'][1]['metadata']['role'])->toBe('draft');
    expect($history['steps'][2]['metadata']['node_id'])->toBe('editor_node');
    expect($history['steps'][2]['metadata']['parent_parallel_node_id'])->toBe('parallel_node');
    expect($history['steps'][2]['metadata']['role'])->toBe('review');
});

test('hierarchical swarm dispatches lifecycle events for coordinator and workers', function () {
    Event::fake();

    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'next' => 'editor_node',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-task',
            ],
        ]),
    ]);

    $response = FakeHierarchicalMultiRouteSwarm::make()->run('hierarchical-task');

    Event::assertDispatched(SwarmStarted::class, fn (SwarmStarted $event) => $event->runId === $response->metadata['run_id']
        && $event->input === 'hierarchical-task'
        && $event->topology === 'hierarchical'
        && $event->executionMode === 'run');
    Event::assertDispatchedTimes(SwarmStepStarted::class, 3);
    Event::assertDispatchedTimes(SwarmStepCompleted::class, 3);
    Event::assertDispatched(SwarmCompleted::class, fn (SwarmCompleted $event) => $event->runId === $response->metadata['run_id']
        && $event->output === 'editor-out'
        && $event->metadata['coordinator_agent_class'] === FakeHierarchicalCoordinator::class
        && $event->metadata['executed_node_ids'] === ['writer_node', 'editor_node']);
});

test('queued hierarchical execution honors the validated plan contract', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'next' => 'editor_node',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-task',
            ],
        ]),
    ]);

    $context = RunContext::from('queued-hierarchical-task', 'queued-hierarchical-run-id');
    $job = new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload());

    $response = $job->handle(app(SwarmRunner::class));

    FakeWriter::assertPrompted('writer-task');
    FakeEditor::assertPrompted('editor-task');
    expect($response)->toBeNull();
    $history = app(RunHistoryStore::class)->find('queued-hierarchical-run-id');
    expect($history['status'])->toBe('completed');
    expect($history['metadata']['execution_mode'])->toBe('queue');
    expect($history['metadata']['executed_node_ids'])->toBe(['writer_node', 'editor_node']);
});

test('queued hierarchical parallel groups run sequentially when coordination is in_process (default)', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ]),
    ]);

    $context = RunContext::from('queued-hierarchical-task', 'queued-hierarchical-parallel-run-id');
    $job = new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload());

    $job->handle(app(SwarmRunner::class));

    $history = app(RunHistoryStore::class)->find('queued-hierarchical-parallel-run-id');

    expect(array_map(fn (array $step): string => $step['metadata']['node_id'] ?? 'coordinator', $history['steps']))->toBe([
        'coordinator',
        'writer_node',
        'editor_node',
    ]);
});

test('parallel branch sibling output dependencies fail validation in run mode', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
                'with_outputs' => ['draft' => 'writer_node'],
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalMultiRouteSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical worker node [editor_node] cannot map output alias [draft] from [writer_node] before that node has completed.');

    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
});

test('parallel branch sibling output dependencies fail validation in queue mode', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
                'with_outputs' => ['draft' => 'writer_node'],
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ]),
    ]);

    $context = RunContext::from('queued-hierarchical-task', 'queued-invalid-sibling-output-run-id');
    $job = new InvokeSwarm(FakeHierarchicalMultiRouteSwarm::class, $context->toQueuePayload());

    expect(fn () => $job->handle(app(SwarmRunner::class)))
        ->toThrow(SwarmException::class, 'Hierarchical worker node [editor_node] cannot map output alias [draft] from [writer_node] before that node has completed.');

    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
});

test('worker nodes cannot reference downstream future outputs', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'with_outputs' => ['review' => 'editor_node'],
                'next' => 'editor_node',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-task',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalMultiRouteSwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical worker node [writer_node] cannot map output alias [review] from [editor_node] before that node has completed.');

    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
});

test('finish nodes cannot reference future outputs', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('finish_node', [
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'writer_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
            ],
        ]),
    ]);

    expect(fn () => FakeHierarchicalCoordinatorOnlySwarm::make()->run('hierarchical-task'))
        ->toThrow(SwarmException::class, 'Hierarchical route plan contains unreachable node [writer_node].');

    FakeWriter::assertNeverPrompted();
});

test('workers after parallel groups can reference all branch outputs in run mode', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'join_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
            ],
            'join_node' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'combine-branches',
                'with_outputs' => [
                    'draft' => 'writer_node',
                    'review' => 'editor_node',
                ],
            ],
        ]),
    ]);

    $response = FakeHierarchicalFullSwarm::make()->run('hierarchical-task');

    expect($response->output)->toBe('research-out');
    FakeResearcher::assertPrompted(<<<'PROMPT'
combine-branches

Named outputs:
[draft]
writer-out

[review]
editor-out
PROMPT);
});

test('workers after parallel groups can reference all branch outputs in queue mode', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'join_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-branch',
            ],
            'join_node' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'combine-branches',
                'with_outputs' => [
                    'draft' => 'writer_node',
                    'review' => 'editor_node',
                ],
            ],
        ]),
    ]);

    $context = RunContext::from('queued-hierarchical-task', 'queued-valid-post-parallel-outputs-run-id');
    $job = new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload());

    $job->handle(app(SwarmRunner::class));

    FakeResearcher::assertPrompted(<<<'PROMPT'
combine-branches

Named outputs:
[draft]
writer-out

[review]
editor-out
PROMPT);
});

test('hierarchical worker nodes resolve named upstream outputs', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'next' => 'editor_node',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'editor-task',
                'with_outputs' => [
                    'draft' => 'writer_node',
                    'draft_copy' => 'writer_node',
                ],
            ],
        ]),
    ]);

    FakeHierarchicalMultiRouteSwarm::make()->run('hierarchical-task');

    FakeEditor::assertPrompted(<<<'PROMPT'
editor-task

Named outputs:
[draft]
writer-out

[draft_copy]
writer-out
PROMPT);
});

test('hierarchical finish nodes can resolve their output from a prior node', function () {
    FakeHierarchicalCoordinator::fake([
        hierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'next' => 'finish_node',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'writer_node',
            ],
        ]),
    ]);

    $response = FakeHierarchicalSingleRouteSwarm::make()->run('hierarchical-task');

    expect($response->output)->toBe('writer-out');
});
