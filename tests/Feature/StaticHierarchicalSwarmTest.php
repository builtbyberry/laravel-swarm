<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeReviewer;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Jobs\NoOpQueuedJob;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalChainSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalLoopSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalMissingInterfaceSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalNestedLoopSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalOverBudgetSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalParallelInLoopSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalParallelWithSynthesisSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalSingleWorkerSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamConcurrentSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamMixedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamSequentialParallelInLoopSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamSequentialParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamSequentialSwarm;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;

// -------------------------------------------------------------------------
// Sync scenarios (prompt() / queue())
// -------------------------------------------------------------------------

beforeEach(function () {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('static hierarchical swarm executes a single-worker plan', function () {
    $response = FakeStaticHierarchicalSingleWorkerSwarm::make()->run('static-task');

    expect($response->output)->toBe('writer-out');
    expect($response->steps)->toHaveCount(1);
    expect($response->metadata['topology'])->toBe('static_hierarchical');
    expect($response->metadata['route_plan_start'])->toBe('writer_node');
    expect($response->metadata['executed_node_ids'])->toBe(['writer_node', 'finish']);
    expect($response->metadata['executed_agent_classes'])->toBe([FakeWriter::class]);
    expect($response->metadata)->not->toHaveKey('coordinator_agent_class');

    FakeWriter::assertPrompted('static-writer-task');
});

test('static hierarchical bounded loop runs the looped worker to its iteration bound', function () {
    FakeWriter::fake(['draft-out']);
    FakeEditor::fake(['refine-1', 'refine-2', 'refine-3']);

    $response = FakeStaticHierarchicalLoopSwarm::make()->run('loop-task');

    // Writer once, then editor three times (max_iterations), then finish.
    expect($response->output)->toBe('refine-3')
        ->and($response->steps)->toHaveCount(4)
        ->and($response->metadata['executed_node_ids'])->toBe([
            'writer_node',
            'editor_node',
            'editor_node',
            'editor_node',
            'finish',
        ])
        ->and($response->metadata['executed_agent_classes'])->toBe([
            FakeWriter::class,
            FakeEditor::class,
            FakeEditor::class,
            FakeEditor::class,
        ]);

    // The loop terminates at the bound rather than re-dispatching forever.
    $loopSteps = array_values(array_filter(
        $response->steps,
        static fn ($step) => ($step->metadata['node_id'] ?? null) === 'editor_node',
    ));

    expect($loopSteps)->toHaveCount(3)
        ->and($loopSteps[0]->metadata['loop_iteration'])->toBe(1)
        ->and($loopSteps[1]->metadata['loop_iteration'])->toBe(2)
        ->and($loopSteps[2]->metadata['loop_iteration'])->toBe(3);
});

test('static hierarchical swarm rejects an unbounded loop plan', function () {
    $swarm = new #[Topology(BuiltByBerry\LaravelSwarm\Enums\Topology::StaticHierarchical)] class implements HasRoutePlan, Swarm
    {
        use Runnable;

        public function agents(): array
        {
            return [new FakeWriter];
        }

        public function plan(): array
        {
            return [
                'start_at' => 'writer_node',
                'nodes' => [
                    'writer_node' => [
                        'type' => 'worker',
                        'agent' => FakeWriter::class,
                        'prompt' => 'Write.',
                        'next' => 'finish',
                        'loop' => ['to' => 'writer_node'],
                    ],
                    'finish' => ['type' => 'finish', 'output_from' => 'writer_node'],
                ],
            ];
        }
    };

    expect(fn () => $swarm->run('unbounded'))
        ->toThrow(SwarmException::class, 'Unbounded loops are not supported.');

    FakeWriter::assertNeverPrompted();
});

test('static hierarchical swarm executes a parallel group followed by a synthesis join', function () {
    $response = FakeStaticHierarchicalParallelWithSynthesisSwarm::make()->run('parallel-task');

    expect($response->output)->toBe('editor-out');
    expect($response->steps)->toHaveCount(3);
    expect($response->metadata['executed_node_ids'])->toBe([
        'parallel_gather',
        'researcher_node',
        'writer_node',
        'editor_node',
        'finish',
    ]);
    expect($response->metadata['executed_agent_classes'])->toBe([
        FakeResearcher::class,
        FakeWriter::class,
        FakeEditor::class,
    ]);
    expect($response->metadata['parallel_groups'])->toBe([
        ['node_id' => 'parallel_gather', 'branches' => ['researcher_node', 'writer_node']],
    ]);
    expect($response->metadata)->not->toHaveKey('coordinator_agent_class');
});

test('static hierarchical synthesis node receives named outputs from parallel branches', function () {
    FakeStaticHierarchicalParallelWithSynthesisSwarm::make()->run('parallel-task');

    FakeEditor::assertPrompted(<<<'PROMPT'
synthesize-task

Named outputs:
[research]
research-out

[draft]
writer-out
PROMPT);
});

test('static hierarchical swarm executes a sequential worker chain with with_outputs', function () {
    $response = FakeStaticHierarchicalChainSwarm::make()->run('chain-task');

    expect($response->output)->toBe('writer-out');
    expect($response->steps)->toHaveCount(2);
    expect($response->metadata['executed_agent_classes'])->toBe([FakeResearcher::class, FakeWriter::class]);
    expect($response->metadata)->not->toHaveKey('coordinator_agent_class');

    FakeResearcher::assertPrompted('research-task');
    FakeWriter::assertPrompted(<<<'PROMPT'
write-task

Named outputs:
[research]
research-out
PROMPT);
});

test('static hierarchical finish node resolves output_from the referenced worker', function () {
    // The synthesis swarm's finish node uses output_from: editor_node
    $response = FakeStaticHierarchicalParallelWithSynthesisSwarm::make()->run('synthesis-task');

    expect($response->output)->toBe('editor-out');
});

test('static hierarchical finish node can use a literal output string', function () {
    // Create an anonymous swarm whose finish node uses output: 'done-literal'
    $swarm = new #[Topology(BuiltByBerry\LaravelSwarm\Enums\Topology::StaticHierarchical)] class implements HasRoutePlan, Swarm
    {
        use Runnable;

        public function agents(): array
        {
            return [new FakeWriter];
        }

        public function plan(): array
        {
            return [
                'start_at' => 'finish',
                'nodes' => [
                    'finish' => [
                        'type' => 'finish',
                        'output' => 'done-literal',
                    ],
                ],
            ];
        }
    };

    $response = $swarm->run('literal-task');

    expect($response->output)->toBe('done-literal');
    FakeWriter::assertNeverPrompted();
});

test('static hierarchical swarm counts only workers toward MaxAgentSteps budget', function () {
    // FakeStaticHierarchicalOverBudgetSwarm has #[MaxAgentSteps(1)] and a 2-worker plan
    expect(fn () => FakeStaticHierarchicalOverBudgetSwarm::make()->run('budget-task'))
        ->toThrow(
            SwarmException::class,
            FakeStaticHierarchicalOverBudgetSwarm::class.': static route plan requires 2 agent executions but the swarm allows 1.'
        );

    FakeResearcher::assertNeverPrompted();
    FakeWriter::assertNeverPrompted();
});

test('static hierarchical swarm throws when HasRoutePlan is not implemented', function () {
    expect(fn () => FakeStaticHierarchicalMissingInterfaceSwarm::make()->run('no-plan-task'))
        ->toThrow(
            SwarmException::class,
            FakeStaticHierarchicalMissingInterfaceSwarm::class.': static hierarchical swarms must implement HasRoutePlan and define a plan() method.'
        );

    FakeWriter::assertNeverPrompted();
});

test('static hierarchical swarm throws when agents() returns an empty array', function () {
    $swarm = new #[Topology(BuiltByBerry\LaravelSwarm\Enums\Topology::StaticHierarchical)] class implements HasRoutePlan, Swarm
    {
        use Runnable;

        public function agents(): array
        {
            return [];
        }

        public function plan(): array
        {
            return ['start_at' => 'finish', 'nodes' => ['finish' => ['type' => 'finish', 'output' => 'done']]];
        }
    };

    expect(fn () => $swarm->run('empty-task'))
        ->toThrow(SwarmException::class, 'swarm has no agents. Add at least one agent to agents().');
});

test('static hierarchical swarm rejects plans with unknown node references before any agent runs', function () {
    $swarm = new #[Topology(BuiltByBerry\LaravelSwarm\Enums\Topology::StaticHierarchical)] class implements HasRoutePlan, Swarm
    {
        use Runnable;

        public function agents(): array
        {
            return [new FakeWriter];
        }

        public function plan(): array
        {
            return [
                'start_at' => 'writer_node',
                'nodes' => [
                    'writer_node' => [
                        'type' => 'worker',
                        'agent' => FakeWriter::class,
                        'prompt' => 'task',
                        'next' => 'missing_node',
                    ],
                ],
            ];
        }
    };

    expect(fn () => $swarm->run('invalid-plan-task'))
        ->toThrow(SwarmException::class, 'Hierarchical route node [writer_node] references unknown node [missing_node].');

    FakeWriter::assertNeverPrompted();
});

test('static hierarchical dispatchDurable() returns a DurableSwarmResponse', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(DurableSwarmManager::class);

    $response = FakeStaticHierarchicalSingleWorkerSwarm::make()->dispatchDurable('durable-task');

    expect($response)->toBeInstanceOf(DurableSwarmResponse::class);
});

test('queued static hierarchical job runs and records completion in history', function () {
    $context = RunContext::from('queued-static-task', 'queued-static-run-id');
    $job = new InvokeSwarm(FakeStaticHierarchicalSingleWorkerSwarm::class, $context->toQueuePayload());

    $job->handle(app(SwarmRunner::class));

    FakeWriter::assertPrompted('static-writer-task');

    $history = app(RunHistoryStore::class)->find('queued-static-run-id');
    expect($history['status'])->toBe('completed');
    expect($history['metadata']['execution_mode'])->toBe('queue');
    expect($history['metadata']['topology'])->toBe('static_hierarchical');
    expect($history['metadata']['executed_node_ids'])->toBe(['writer_node', 'finish']);
    expect($history['metadata'])->not->toHaveKey('coordinator_agent_class');
});

test('static hierarchical swarm dispatches correct lifecycle events', function () {
    Event::fake();

    $response = FakeStaticHierarchicalSingleWorkerSwarm::make()->run('events-task');

    Event::assertDispatched(SwarmStarted::class, function (SwarmStarted $event) use ($response) {
        return $event->runId === $response->metadata['run_id']
            && $event->topology === 'static_hierarchical'
            && $event->executionMode === 'run';
    });

    Event::assertDispatchedTimes(SwarmStepStarted::class, 1);
    Event::assertDispatchedTimes(SwarmStepCompleted::class, 1);

    Event::assertDispatched(SwarmCompleted::class, function (SwarmCompleted $event) use ($response) {
        return $event->runId === $response->metadata['run_id']
            && $event->output === 'writer-out'
            && $event->topology === 'static_hierarchical'
            && ! isset($event->metadata['coordinator_agent_class']);
    });
});

test('static hierarchical swarm rejects duplicate worker agent classes', function () {
    $swarm = new #[Topology(BuiltByBerry\LaravelSwarm\Enums\Topology::StaticHierarchical)] class implements HasRoutePlan, Swarm
    {
        use Runnable;

        public function agents(): array
        {
            return [new FakeWriter, new FakeWriter];
        }

        public function plan(): array
        {
            return [
                'start_at' => 'writer_node',
                'nodes' => [
                    'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'task', 'next' => 'finish'],
                    'finish' => ['type' => 'finish', 'output_from' => 'writer_node'],
                ],
            ];
        }
    };

    expect(fn () => $swarm->run('duplicate-task'))
        ->toThrow(SwarmException::class, FakeWriter::class.'. Hierarchical worker classes must be unique.');

    FakeWriter::assertNeverPrompted();
});

// -------------------------------------------------------------------------
// Stream scenarios
// -------------------------------------------------------------------------

test('static hierarchical swarm streams sequential workers in order', function () {
    $stream = FakeStaticHierarchicalStreamSequentialSwarm::make()->stream('stream-task');

    expect($stream)->toBeInstanceOf(StreamableSwarmResponse::class);

    $events = iterator_to_array($stream);
    $types = array_map(fn ($event): string => $event->type(), $events);

    // Both sequential workers stream live text. Each worker node is also a
    // structural node (#284): it opens before its content, declares its decided
    // child as it routes forward, and closes — bracketing the deliberation.
    expect($types)->toBe([
        'swarm_stream_start',
        // researcher node: opens, streams, decides writer_node, closes
        'swarm_node_opened',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        'swarm_node_children_decided',
        'swarm_node_closed',
        // writer node: opens, streams, decides finish, closes
        'swarm_node_opened',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        'swarm_node_children_decided',
        'swarm_node_closed',
        'swarm_stream_end',
    ]);

    expect($events[0])->toBeInstanceOf(SwarmStreamStart::class);

    $textDeltas = collect($events)->whereInstanceOf(SwarmTextDelta::class)->values();
    expect($textDeltas[0]->delta)->toBe('research-out');
    expect($textDeltas[1]->delta)->toBe('writer-out');

    $stepStarts = collect($events)->whereInstanceOf(SwarmStepStart::class)->values();
    expect($stepStarts[0]->agentClass)->toBe(FakeResearcher::class);
    expect($stepStarts[1]->agentClass)->toBe(FakeWriter::class);

    expect(last($events))->toBeInstanceOf(SwarmStreamEnd::class);
    expect(last($events)->output)->toBe('writer-out');
});

test('static hierarchical stream step events carry correct step indices', function () {
    $events = iterator_to_array(FakeStaticHierarchicalStreamSequentialSwarm::make()->stream('step-index-task'));

    $stepStarts = collect($events)->whereInstanceOf(SwarmStepStart::class)->values();
    $stepEnds = collect($events)->whereInstanceOf(SwarmStepEnd::class)->values();

    expect($stepStarts[0]->stepIndex)->toBe(0);
    expect($stepStarts[1]->stepIndex)->toBe(1);
    expect($stepEnds[0]->stepIndex)->toBe(0);
    expect($stepEnds[1]->stepIndex)->toBe(1);
});

test('static hierarchical stream defaults to concurrent mode for parallel groups', function () {
    // FakeStaticHierarchicalStreamConcurrentSwarm has no #[StreamParallelBranches] — uses default 'concurrent'
    $events = iterator_to_array(FakeStaticHierarchicalStreamConcurrentSwarm::make()->stream('concurrent-stream-task'));
    $types = array_map(fn ($event): string => $event->type(), $events);

    // Concurrent mode: parallel branches produce step_end (no step_start, no text_delta)
    // Sequential synthesis step after join streams normally
    // Concurrent parallel branches go through the fan-out path and are NOT
    // wrapped in node grammar (#284); only the sequential synthesis worker opens
    // a structural node, decides its child (finish), and closes.
    expect($types)->toBe([
        'swarm_stream_start',
        // concurrent branches — only step_end events (no step_start, no text deltas)
        'swarm_step_end',
        'swarm_step_end',
        // synthesis worker streams live, bracketed by its node grammar
        'swarm_node_opened',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        'swarm_node_children_decided',
        'swarm_node_closed',
        'swarm_stream_end',
    ]);

    // No text delta from concurrent branches
    $textDeltas = collect($events)->whereInstanceOf(SwarmTextDelta::class)->values();
    expect($textDeltas)->toHaveCount(1);
    expect($textDeltas[0]->delta)->toBe('editor-out');

    // Synthesis receives with_outputs from both branches
    FakeEditor::assertPrompted(<<<'PROMPT'
stream-synthesize-task

Named outputs:
[research]
research-out

[draft]
writer-out
PROMPT);

    expect(last($events)->output)->toBe('editor-out');
});

test('static hierarchical stream sequential mode streams all branches in declaration order', function () {
    // FakeStaticHierarchicalStreamSequentialParallelSwarm has #[StreamParallelBranches('sequential')]
    $events = iterator_to_array(FakeStaticHierarchicalStreamSequentialParallelSwarm::make()->stream('sequential-parallel-task'));
    $types = array_map(fn ($event): string => $event->type(), $events);

    // Sequential mode: each branch fully streams (step_start, text_delta, text_end, step_end)
    // Then synthesis also streams
    // Sequential parallel branches stream through the fan-out path and are NOT
    // wrapped in node grammar (#284); only the sequential synthesis worker opens
    // a structural node, decides its child (finish), and closes.
    expect($types)->toBe([
        'swarm_stream_start',
        // researcher branch
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        // writer branch
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        // synthesis worker, bracketed by its node grammar
        'swarm_node_opened',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        'swarm_node_children_decided',
        'swarm_node_closed',
        'swarm_stream_end',
    ]);

    $textDeltas = collect($events)->whereInstanceOf(SwarmTextDelta::class)->values();
    expect($textDeltas)->toHaveCount(3);
    expect($textDeltas[0]->delta)->toBe('research-out');
    expect($textDeltas[1]->delta)->toBe('writer-out');
    expect($textDeltas[2]->delta)->toBe('editor-out');

    $stepStarts = collect($events)->whereInstanceOf(SwarmStepStart::class)->values();
    expect($stepStarts[0]->agentClass)->toBe(FakeResearcher::class);
    expect($stepStarts[1]->agentClass)->toBe(FakeWriter::class);
    expect($stepStarts[2]->agentClass)->toBe(FakeEditor::class);
});

test('static hierarchical stream handles mixed plan: sequential → concurrent parallel → finish', function () {
    // FakeStaticHierarchicalStreamMixedSwarm: Researcher → parallel[Writer, Editor] → finish(output_from: writer_node)
    $events = iterator_to_array(FakeStaticHierarchicalStreamMixedSwarm::make()->stream('mixed-task'));
    $types = array_map(fn ($event): string => $event->type(), $events);

    // Sequential researcher streams first, then concurrent parallel branches emit step_end only
    // The sequential researcher is a structural node (#284): it opens, streams,
    // decides its child (the parallel fan-out node), and closes. The concurrent
    // branches themselves are not wrapped in node grammar.
    expect($types)->toBe([
        'swarm_stream_start',
        // sequential researcher, bracketed by its node grammar
        'swarm_node_opened',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        'swarm_node_children_decided',
        'swarm_node_closed',
        // concurrent parallel branches — only step_end
        'swarm_step_end',
        'swarm_step_end',
        // finish node resolves output_from: writer_node
        'swarm_stream_end',
    ]);

    $textDeltas = collect($events)->whereInstanceOf(SwarmTextDelta::class)->values();
    expect($textDeltas)->toHaveCount(1);
    expect($textDeltas[0]->delta)->toBe('research-out');

    expect(last($events)->output)->toBe('writer-out');
});

test('#[StreamParallelBranches] attribute overrides config default for stream mode', function () {
    // Sequential mode swarm should stream each branch (3 text deltas total)
    $events = iterator_to_array(FakeStaticHierarchicalStreamSequentialParallelSwarm::make()->stream('attr-override-task'));
    $textDeltas = collect($events)->whereInstanceOf(SwarmTextDelta::class)->values();

    // If config was 'concurrent', only synthesis would stream (1 delta). Sequential means 3.
    expect($textDeltas)->toHaveCount(3);
});

test('config default concurrent mode is used when no attribute is present', function () {
    // FakeStaticHierarchicalStreamConcurrentSwarm has no #[StreamParallelBranches]
    config()->set('swarm.static_hierarchical.stream_parallel_branches', 'concurrent');

    $events = iterator_to_array(FakeStaticHierarchicalStreamConcurrentSwarm::make()->stream('config-default-task'));
    $types = array_map(fn ($event): string => $event->type(), $events);

    // Concurrent: branches are step_end only, synthesis streams
    expect(in_array('swarm_step_end', $types))->toBeTrue();

    $stepStarts = collect($events)->whereInstanceOf(SwarmStepStart::class)->values();
    // Only the synthesis step_start is present, not branch step_starts
    expect($stepStarts)->toHaveCount(1);
    expect($stepStarts[0]->agentClass)->toBe(FakeEditor::class);
});

test('static hierarchical stream dispatches SwarmStarted and SwarmCompleted lifecycle events', function () {
    Event::fake();

    $stream = FakeStaticHierarchicalStreamSequentialSwarm::make()->stream('lifecycle-stream-task');
    $events = iterator_to_array($stream);

    Event::assertDispatched(SwarmStarted::class, function (SwarmStarted $event) {
        return $event->topology === 'static_hierarchical'
            && $event->executionMode === 'stream';
    });

    Event::assertDispatched(SwarmCompleted::class, function (SwarmCompleted $event) {
        return $event->output === 'writer-out'
            && $event->topology === 'static_hierarchical'
            && ! isset($event->metadata['coordinator_agent_class'])
            && $event->metadata['executed_node_ids'] === ['researcher_node', 'writer_node', 'finish']
            && $event->metadata['executed_agent_classes'] === [FakeResearcher::class, FakeWriter::class]
            && $event->metadata['parallel_groups'] === []
            && $event->metadata['executed_steps'] === 2;
    });

    Event::assertDispatchedTimes(SwarmStepStarted::class, 2);
    Event::assertDispatchedTimes(SwarmStepCompleted::class, 2);
});

test('static hierarchical stream metadata includes parallel_groups when concurrent branches execute', function () {
    Event::fake();

    iterator_to_array(FakeStaticHierarchicalStreamConcurrentSwarm::make()->stream('metadata-concurrent-task'));

    Event::assertDispatched(SwarmCompleted::class, function (SwarmCompleted $event) {
        return $event->metadata['executed_node_ids'] === ['parallel_gather', 'researcher_node', 'writer_node', 'editor_node', 'finish']
            && $event->metadata['executed_agent_classes'] === [FakeResearcher::class, FakeWriter::class, FakeEditor::class]
            && $event->metadata['parallel_groups'] === [
                ['node_id' => 'parallel_gather', 'branches' => ['researcher_node', 'writer_node']],
            ]
            && $event->metadata['executed_steps'] === 3;
    });
});

test('broadcastOnQueue() accepts static hierarchical swarms without throwing', function () {
    $response = FakeStaticHierarchicalStreamSequentialSwarm::make()
        ->broadcastOnQueue('broadcast-queue-task', new Channel('swarm.test'));

    expect($response)->toBeInstanceOf(QueuedSwarmResponse::class);
    expect($response->getJob())->toBeInstanceOf(BroadcastSwarm::class);
    expect($response->runId)->not->toBeNull();

    // Prevent the pending dispatch from being sent by swapping the underlying job
    $dispatchableProperty = new ReflectionProperty($response, 'dispatchable');
    $dispatchableProperty->setAccessible(true);
    $dispatchable = $dispatchableProperty->getValue($response);
    $jobProperty = new ReflectionProperty($dispatchable, 'job');
    $jobProperty->setAccessible(true);
    $jobProperty->setValue($dispatchable, new NoOpQueuedJob);
});

test('broadcast() routes a static hierarchical swarm through the stream runner', function () {
    $response = FakeStaticHierarchicalSingleWorkerSwarm::make()
        ->broadcast('broadcast-task', new Channel('swarm.test'));

    // broadcast() consumes the stream internally via each(); $response is the StreamableSwarmResponse.
    expect($response)->toBeInstanceOf(StreamableSwarmResponse::class);
    expect($response->streamedResponse)->not->toBeNull();
    expect($response->streamedResponse->metadata['topology'])->toBe('static_hierarchical');
});

test('broadcastNow() routes a static hierarchical swarm through the stream runner', function () {
    $response = FakeStaticHierarchicalSingleWorkerSwarm::make()
        ->broadcastNow('broadcast-now-task', new Channel('swarm.test'));

    expect($response)->toBeInstanceOf(StreamableSwarmResponse::class);
    expect($response->streamedResponse)->not->toBeNull();
    expect($response->streamedResponse->metadata['topology'])->toBe('static_hierarchical');
});

// -------------------------------------------------------------------------
// Stream scenarios: bounded loops (#203 — parity with prompt()/queue()/dispatchDurable())
// -------------------------------------------------------------------------

test('static hierarchical stream honors a bounded loop to its iteration bound', function () {
    FakeWriter::fake(['draft-out']);
    FakeEditor::fake(['refine-1', 'refine-2', 'refine-3']);

    $stream = FakeStaticHierarchicalLoopSwarm::make()->stream('loop-stream-task');
    $events = iterator_to_array($stream);

    // Writer streams once, then the editor streams three times (max_iterations),
    // then the run ends — parity with the sync loop test, not body-once-and-exit.
    // A looping node opens once on its first iteration and closes once when the
    // loop exits (#284): the writer brackets its single pass and decides the
    // editor; the editor opens on iteration 1, re-runs without re-opening on
    // iterations 2 and 3, then decides finish and closes on the bound.
    $types = array_map(fn ($event): string => $event->type(), $events);
    expect($types)->toBe([
        'swarm_stream_start',
        // writer node (single pass)
        'swarm_node_opened',
        'swarm_step_start', 'swarm_text_delta', 'swarm_text_end', 'swarm_step_end',
        'swarm_node_children_decided', 'swarm_node_closed',
        // editor node iteration 1 — opens, but the loop back-edge defers its close
        'swarm_node_opened',
        'swarm_step_start', 'swarm_text_delta', 'swarm_text_end', 'swarm_step_end',
        // editor iterations 2 and 3 re-run under the open node (no re-open)
        'swarm_step_start', 'swarm_text_delta', 'swarm_text_end', 'swarm_step_end',
        'swarm_step_start', 'swarm_text_delta', 'swarm_text_end', 'swarm_step_end',
        // the bound is reached: editor decides finish and closes
        'swarm_node_children_decided', 'swarm_node_closed',
        'swarm_stream_end',
    ]);

    $deltas = collect($events)->whereInstanceOf(SwarmTextDelta::class)->map->delta->values();
    expect($deltas->all())->toBe(['draft-out', 'refine-1', 'refine-2', 'refine-3']);
    expect(last($events)->output)->toBe('refine-3');

    // Step indices stay monotonic across loop passes (each pass is its own step).
    $stepStarts = collect($events)->whereInstanceOf(SwarmStepStart::class)->map->stepIndex->values();
    expect($stepStarts->all())->toBe([0, 1, 2, 3]);

    // The recorded history mirrors the sync path: looped node executed thrice,
    // each step stamped with its loop_iteration.
    $history = app(RunHistoryStore::class)->find($stream->runId);
    expect($history['metadata']['executed_node_ids'])->toBe([
        'writer_node', 'editor_node', 'editor_node', 'editor_node', 'finish',
    ]);

    $editorIterations = array_values(array_map(
        static fn (array $step): ?int => $step['metadata']['loop_iteration'] ?? null,
        array_filter($history['steps'], static fn (array $s): bool => ($s['metadata']['node_id'] ?? null) === 'editor_node'),
    ));
    expect($editorIterations)->toBe([1, 2, 3]);
});

test('static hierarchical stream rejects an unbounded loop plan', function () {
    $swarm = new #[Topology(BuiltByBerry\LaravelSwarm\Enums\Topology::StaticHierarchical)] class implements HasRoutePlan, Swarm
    {
        use Runnable;

        public function agents(): array
        {
            return [new FakeWriter];
        }

        public function plan(): array
        {
            return [
                'start_at' => 'writer_node',
                'nodes' => [
                    'writer_node' => [
                        'type' => 'worker',
                        'agent' => FakeWriter::class,
                        'prompt' => 'Write.',
                        'next' => 'finish',
                        'loop' => ['to' => 'writer_node'],
                    ],
                    'finish' => ['type' => 'finish', 'output_from' => 'writer_node'],
                ],
            ];
        }
    };

    expect(fn () => iterator_to_array($swarm->stream('unbounded-stream')))
        ->toThrow(SwarmException::class, 'Unbounded loops are not supported.');

    FakeWriter::assertNeverPrompted();
});

test('static hierarchical stream re-runs a parallel group inside a loop, stamping each pass', function () {
    FakeEditor::fake(array_fill(0, 20, 'gather-out'));
    FakeResearcher::fake(array_fill(0, 20, 'research-out'));
    FakeWriter::fake(array_fill(0, 20, 'write-out'));
    FakeReviewer::fake(array_fill(0, 20, 'review-out'));

    $stream = FakeStaticHierarchicalParallelInLoopSwarm::make()->stream('par-loop-stream');
    iterator_to_array($stream);

    $history = app(RunHistoryStore::class)->find($stream->runId);
    $executed = $history['metadata']['executed_node_ids'];
    $count = fn (string $id): int => count(array_filter($executed, static fn (string $n): bool => $n === $id));

    // The fan-out and both branches re-run on every one of the three passes.
    expect($count('gather'))->toBe(3)
        ->and($count('fan_out'))->toBe(3)
        ->and($count('branch_research'))->toBe(3)
        ->and($count('branch_write'))->toBe(3)
        ->and($count('join'))->toBe(3);

    // Branch steps carry the enclosing loop's iteration — parity with the sync path.
    $branchSteps = array_values(array_filter(
        $history['steps'],
        static fn (array $s): bool => in_array($s['metadata']['node_id'] ?? null, ['branch_research', 'branch_write'], true),
    ));
    expect($branchSteps)->toHaveCount(6);

    $researchIterations = array_values(array_map(
        static fn (array $s): ?int => $s['metadata']['loop_iteration'] ?? null,
        array_filter($branchSteps, static fn (array $s): bool => ($s['metadata']['node_id'] ?? null) === 'branch_research'),
    ));
    expect($researchIterations)->toBe([1, 2, 3]);
});

test('streamed loop replay re-emits recorded events without re-running the loop', function () {
    config()->set('swarm.streaming.replay.enabled', true);

    FakeWriter::fake(['draft-out']);
    // Exactly three editor responses: a loop that wrongly re-executed on replay
    // would prompt a fourth time, exhaust the fakes, and diverge from $first.
    FakeEditor::fake(['refine-1', 'refine-2', 'refine-3']);

    $stream = FakeStaticHierarchicalLoopSwarm::make()->stream('loop-replay-task');

    $first = iterator_to_array($stream);
    $second = iterator_to_array($stream);

    expect($second)->toBe($first);
    expect(last($first)->output)->toBe('refine-3');

    $editorDeltas = collect($first)
        ->whereInstanceOf(SwarmTextDelta::class)
        ->filter(fn ($delta): bool => str_starts_with($delta->delta, 'refine-'))
        ->values();
    expect($editorDeltas)->toHaveCount(3);
});

test('static hierarchical stream resets inner loop counters on every outer pass', function () {
    FakeWriter::fake(array_fill(0, 20, 'draft-out'));
    FakeEditor::fake(array_fill(0, 20, 'refine-out'));
    FakeReviewer::fake(array_fill(0, 20, 'review-out'));

    $stream = FakeStaticHierarchicalNestedLoopSwarm::make()->stream('nested-loop-stream');
    iterator_to_array($stream);

    // The inner loop (max 3) must run its full count on each of the two outer
    // passes — only possible if the streamed inner counter resets on the outer
    // back-edge. Parity with the sync nested-loop test.
    $history = app(RunHistoryStore::class)->find($stream->runId);
    expect($history['metadata']['executed_node_ids'])->toBe([
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

test('static hierarchical stream stamps loop_iteration on sequential parallel-in-loop branches', function () {
    FakeEditor::fake(array_fill(0, 20, 'gather-out'));
    FakeResearcher::fake(array_fill(0, 20, 'research-out'));
    FakeWriter::fake(array_fill(0, 20, 'write-out'));
    FakeReviewer::fake(array_fill(0, 20, 'review-out'));

    $stream = FakeStaticHierarchicalStreamSequentialParallelInLoopSwarm::make()->stream('seq-par-loop-stream');
    iterator_to_array($stream);

    // Sequential branch mode reaches a different stamping site than concurrent;
    // each branch step must still carry the enclosing loop's iteration (1,2,3).
    $history = app(RunHistoryStore::class)->find($stream->runId);
    $branchSteps = array_values(array_filter(
        $history['steps'],
        static fn (array $s): bool => in_array($s['metadata']['node_id'] ?? null, ['branch_research', 'branch_write'], true),
    ));
    expect($branchSteps)->toHaveCount(6);

    $researchIterations = array_values(array_map(
        static fn (array $s): ?int => $s['metadata']['loop_iteration'] ?? null,
        array_filter($branchSteps, static fn (array $s): bool => ($s['metadata']['node_id'] ?? null) === 'branch_research'),
    ));
    expect($researchIterations)->toBe([1, 2, 3]);
});
