<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FailingPromptAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalParallelFailBranchSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function configureQueuedHierarchicalParallelRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    // Prevent branch/resume jobs from running during InvokeSwarm; tests dispatch them explicitly.
    config()->set('queue.default', 'null');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');
    config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(DurableRunRecorder::class);
    app()->forgetInstance(QueuedHierarchicalCoordinator::class);
    app()->forgetInstance(DurableSwarmManager::class);
}

function qhpcParallelPlanWithFailingBranch(): array
{
    return [
        'start_at' => 'parallel_node',
        'nodes' => [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'failing_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-branch',
            ],
            'failing_node' => [
                'type' => 'worker',
                'agent' => FailingPromptAgent::class,
                'prompt' => 'failing-branch',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'writer_node',
            ],
        ],
    ];
}

function qhpcParallelPlan(): array
{
    return [
        'start_at' => 'parallel_node',
        'nodes' => [
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
        ],
    ];
}

function qhpcPreParallelOutputPlan(): array
{
    return [
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'pre-parallel-worker',
                'next' => 'parallel_node',
            ],
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['editor_node'],
                'next' => 'researcher_node',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'branch-worker',
            ],
            'researcher_node' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'combine-results',
                'with_outputs' => [
                    'pre' => 'writer_node',
                    'branch' => 'editor_node',
                ],
                'next' => 'finish_node',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'researcher_node',
            ],
        ],
    ];
}

function qhpcPreParallelFinishOutputPlan(): array
{
    return [
        'start_at' => 'writer_node',
        'nodes' => [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'pre-parallel-worker',
                'next' => 'parallel_node',
            ],
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['editor_node'],
                'next' => 'finish_node',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'branch-worker',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'writer_node',
            ],
        ],
    ];
}

beforeEach(function () {
    configureQueuedHierarchicalParallelRuntime();
    FakeHierarchicalCoordinator::fake([qhpcParallelPlan()]);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
    FakeResearcher::fake(['researcher-out']);
});

test('queued hierarchical parallel multi_worker defers branches then completes on resume', function () {
    $context = RunContext::from('queued-hierarchical-task', 'qhpc-multi-1');
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    $history = app(RunHistoryStore::class)->find('qhpc-multi-1');
    expect($history['status'])->toBe('waiting');
    expect($history['metadata']['queue_hierarchical_waiting_parallel'] ?? false)->toBeTrue();

    $manager = app(DurableSwarmManager::class);
    $branches = DB::table('swarm_durable_branches')->where('run_id', 'qhpc-multi-1')->orderBy('step_index')->get();

    expect($branches)->toHaveCount(2);

    foreach ($branches as $branch) {
        (new AdvanceDurableBranch('qhpc-multi-1', (string) $branch->branch_id))->handle($manager);
    }

    (new ResumeQueuedHierarchicalSwarm('qhpc-multi-1'))->handle(app(QueuedHierarchicalCoordinator::class));

    $history = app(RunHistoryStore::class)->find('qhpc-multi-1');
    expect($history['status'])->toBe('completed');
    expect($history['metadata']['execution_mode'])->toBe('queue');
    expect($history['metadata']['executed_steps'])->toBe(3);
    expect($history['metadata']['executed_node_ids'])->toBe(['parallel_node', 'writer_node', 'editor_node', 'finish_node']);
    expect($history['metadata']['executed_agent_classes'])->toBe([FakeWriter::class, FakeEditor::class]);
    expect($history['metadata']['parallel_groups'])->toBe([
        ['node_id' => 'parallel_node', 'branches' => ['writer_node', 'editor_node']],
    ]);
    expect($history['usage'])->not->toBe([]);
    expect($history['metadata']['usage'])->toBe($history['usage']);

    FakeWriter::assertPrompted('writer-branch');
    FakeEditor::assertPrompted('editor-branch');
});

test('queued hierarchical parallel multi_worker preserves pre parallel outputs and accounting across resume', function () {
    FakeHierarchicalCoordinator::fake([qhpcPreParallelOutputPlan()]);
    FakeWriter::fake(['pre-writer-out']);
    FakeEditor::fake(['editor-out']);
    FakeResearcher::fake(['researcher-out']);

    $context = RunContext::from('queued-hierarchical-task', 'qhpc-pre-parallel-1');
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    $waitingContext = app(ContextStore::class)->find('qhpc-pre-parallel-1');
    expect($waitingContext['data']['hierarchical_node_outputs'])->toBe([
        'writer_node' => 'pre-writer-out',
    ]);
    expect($waitingContext['metadata']['executed_steps'])->toBe(2);
    expect($waitingContext['metadata']['executed_agent_classes'])->toBe([FakeWriter::class]);

    $manager = app(DurableSwarmManager::class);
    $branch = DB::table('swarm_durable_branches')->where('run_id', 'qhpc-pre-parallel-1')->first();
    expect($branch)->not->toBeNull();

    (new AdvanceDurableBranch('qhpc-pre-parallel-1', (string) $branch->branch_id))->handle($manager);
    (new ResumeQueuedHierarchicalSwarm('qhpc-pre-parallel-1'))->handle(app(QueuedHierarchicalCoordinator::class));

    $expectedResearchPrompt = "combine-results\n\nNamed outputs:\n[pre]\npre-writer-out\n\n[branch]\neditor-out";
    FakeResearcher::assertPrompted($expectedResearchPrompt);

    $history = app(RunHistoryStore::class)->find('qhpc-pre-parallel-1');
    expect($history['status'])->toBe('completed');
    expect($history['output'])->toBe('researcher-out');
    expect($history['metadata']['execution_mode'])->toBe('queue');
    expect($history['metadata']['executed_steps'])->toBe(4);
    expect($history['metadata']['executed_node_ids'])->toBe(['writer_node', 'parallel_node', 'editor_node', 'researcher_node', 'finish_node']);
    expect($history['metadata']['executed_agent_classes'])->toBe([FakeWriter::class, FakeEditor::class, FakeResearcher::class]);
    expect($history['metadata']['parallel_groups'])->toBe([
        ['node_id' => 'parallel_node', 'branches' => ['editor_node']],
    ]);
    expect($history['context']['data']['hierarchical_node_outputs'])->toBe([
        'writer_node' => 'pre-writer-out',
        'editor_node' => 'editor-out',
        'researcher_node' => 'researcher-out',
    ]);
    expect($history['usage'])->not->toBe([]);
    expect($history['metadata']['usage'])->toBe($history['usage']);
});

test('queued hierarchical parallel multi_worker resolves finish outputs from pre parallel workers after resume', function () {
    FakeHierarchicalCoordinator::fake([qhpcPreParallelFinishOutputPlan()]);
    FakeWriter::fake(['pre-writer-out']);
    FakeEditor::fake(['editor-out']);

    $runId = 'qhpc-pre-parallel-finish-1';
    $context = RunContext::from('queued-hierarchical-task', $runId);
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    $manager = app(DurableSwarmManager::class);
    $branch = DB::table('swarm_durable_branches')->where('run_id', $runId)->first();
    expect($branch)->not->toBeNull();

    (new AdvanceDurableBranch($runId, (string) $branch->branch_id))->handle($manager);
    (new ResumeQueuedHierarchicalSwarm($runId))->handle(app(QueuedHierarchicalCoordinator::class));

    $history = app(RunHistoryStore::class)->find($runId);
    expect($history['status'])->toBe('completed');
    expect($history['output'])->toBe('pre-writer-out');
    expect($history['metadata']['executed_steps'])->toBe(3);
    expect($history['context']['data']['hierarchical_node_outputs'])->toBe([
        'writer_node' => 'pre-writer-out',
        'editor_node' => 'editor-out',
    ]);
});

function staleCoordinationRun(string $runId): void
{
    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update([
            'updated_at' => now('UTC')->subMinutes(10),
        ]);
}

test('swarm recover releases a stale coordinated queue hierarchical waiting join', function () {
    $runId = 'qhpc-recover-1';
    $context = RunContext::from('queued-hierarchical-task', $runId);
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    foreach (DB::table('swarm_durable_branches')->where('run_id', $runId)->get() as $branch) {
        DB::table('swarm_durable_branches')
            ->where('run_id', $runId)
            ->where('branch_id', $branch->branch_id)
            ->update([
                'status' => 'completed',
                'output' => 'recovered-'.$branch->branch_id,
                'usage' => json_encode([]),
                'duration_ms' => 1,
                'failure' => null,
                'finished_at' => now('UTC'),
                'execution_token' => null,
                'leased_until' => null,
                'updated_at' => now('UTC')->subMinutes(10),
            ]);
    }

    staleCoordinationRun($runId);

    Artisan::call('swarm:recover');

    $run = app(DurableSwarmManager::class)->find($runId);
    expect($run['status'])->toBe('pending')
        ->and((int) ($run['recovery_count'] ?? 0))->toBeGreaterThanOrEqual(1);

    (new ResumeQueuedHierarchicalSwarm($runId))->handle(app(QueuedHierarchicalCoordinator::class));

    expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
});

test('queued hierarchical parallel multi_worker fail_run fails primary run when a branch worker throws', function () {
    Event::fake([SwarmFailed::class]);
    config()->set('swarm.durable.parallel.failure_policy', 'fail_run');

    FakeHierarchicalCoordinator::fake([qhpcParallelPlanWithFailingBranch()]);
    FakeWriter::fake(['writer-out']);

    $runId = 'qhpc-fail-run-1';
    $context = RunContext::from('queued-hierarchical-task', $runId);
    (new InvokeSwarm(FakeHierarchicalParallelFailBranchSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting');

    $manager = app(DurableSwarmManager::class);
    $failingBranch = DB::table('swarm_durable_branches')->where('run_id', $runId)->where('agent_class', FailingPromptAgent::class)->first();
    expect($failingBranch)->not->toBeNull();

    (new AdvanceDurableBranch($runId, (string) $failingBranch->branch_id))->handle($manager);

    $history = app(RunHistoryStore::class)->find($runId);
    expect($history['status'])->toBe('failed');

    expect($manager->find($runId)['status'])->toBe('failed');

    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $event): bool => $event->executionMode === 'queue');
});

test('queued hierarchical parallel multi_worker can be cancelled while waiting on branches', function () {
    $context = RunContext::from('queued-hierarchical-task', 'qhpc-cancel-1');
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    app(DurableSwarmManager::class)->cancel('qhpc-cancel-1');

    $history = app(RunHistoryStore::class)->find('qhpc-cancel-1');
    expect($history['status'])->toBe('cancelled');
});
