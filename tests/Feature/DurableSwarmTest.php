<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmStepRecorder;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalLimitedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelFailingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelPartialSuccessSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRoutedParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

function configureDurableRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(DurableRunRecorder::class);

    app()->forgetInstance(DurableSwarmManager::class);
}

function durableHierarchicalPlan(string $startAt, array $nodes): array
{
    return [
        'start_at' => $startAt,
        'nodes' => $nodes,
    ];
}

function ensureJobsTableExists(): void
{
    if (Schema::hasTable('jobs')) {
        return;
    }

    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

function stealDurableLease(string $runId, string $replacementToken = 'replacement-token'): void
{
    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update([
            'execution_token' => $replacementToken,
            'leased_until' => now()->addMinutes(5),
            'updated_at' => now(),
        ]);
}

function expireDurableLease(string $runId): void
{
    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update([
            'leased_until' => now()->subSecond(),
            'updated_at' => now(),
        ]);
}

function dropColumnIfPresent(Builder $schema, string $table, string $column): void
{
    if ($schema->hasColumn($table, $column)) {
        $schema->dropColumns($table, [$column]);
    }
}

beforeEach(function () {
    configureDurableRuntime();
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('dispatch durable accepts structured input and creates a durable run response', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable([
        'draft_id' => 42,
        'tenant_id' => 'acme',
    ])->onQueue('priority-durable');

    expect($response)->toBeInstanceOf(DurableSwarmResponse::class);
    expect($response->runId)->not->toBe('');
    expect($response->getJob())->toBeInstanceOf(AdvanceDurableSwarm::class);
    expect($response->getJob()->queue)->toBe('priority-durable');

    $run = app(DurableSwarmManager::class)->find($response->runId);
    $history = app(SwarmHistory::class)->find($response->runId);

    expect($run)->not->toBeNull()
        ->and($run['status'])->toBe('pending')
        ->and($run['next_step_index'])->toBe(0)
        ->and($run['queue_name'])->toBe('priority-durable');
    expect($history['status'])->toBe('pending');
    expect($history['context']['data'])
        ->toHaveKey('draft_id', 42)
        ->toHaveKey('tenant_id', 'acme');
});

test('durable capture redacts history while preserving active runtime context until terminal', function () {
    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);

    $response = FakeSequentialSwarm::make()->dispatchDurable('sensitive-durable-task');
    $runId = $response->runId;

    expect(app(ContextStore::class)->find($runId)['input'])->toBe('sensitive-durable-task');
    expect(app(SwarmHistory::class)->find($runId)['context']['input'])->toBe('[redacted]');

    app(DurableSwarmManager::class)->pause($runId);
    expect(app(ContextStore::class)->find($runId)['input'])->toBe('sensitive-durable-task');

    app(DurableSwarmManager::class)->resume($runId);
    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('pending');
});

test('durable manager start creates runtime state before the first job is pushed', function () {
    ensureJobsTableExists();

    $context = RunContext::fromTask([
        'draft_id' => 42,
        'tenant_id' => 'acme',
    ]);

    $start = app(DurableSwarmManager::class)->start(
        FakeSequentialSwarm::make(),
        $context,
        Topology::Sequential,
        300,
        3,
    );

    expect($start->runId)->toBe($context->runId)
        ->and($start->job)->toBeInstanceOf(AdvanceDurableSwarm::class)
        ->and($start->job->runId)->toBe($context->runId)
        ->and(DB::table('jobs')->count())->toBe(0);

    $run = app(DurableSwarmManager::class)->find($context->runId);
    $history = app(SwarmHistory::class)->find($context->runId);

    expect($run)->not->toBeNull()
        ->and($run['status'])->toBe('pending')
        ->and($run['next_step_index'])->toBe(0)
        ->and($history['status'])->toBe('pending')
        ->and($history['context']['data'])
        ->toHaveKey('draft_id', 42)
        ->toHaveKey('tenant_id', 'acme');
});

test('dispatch durable supports top level parallel swarms', function () {
    $response = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    $branches = app(DurableRunStore::class)->branchesFor($runId, 'parallel');

    expect($manager->find($runId)['status'])->toBe('waiting')
        ->and($branches)->toHaveCount(3);

    foreach ($branches as $branch) {
        (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
    }

    expect($manager->find($runId)['status'])->toBe('pending')
        ->and($manager->find($runId)['next_step_index'])->toBe(3);

    (new AdvanceDurableSwarm($runId, 3))->handle($manager);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['output'])->toBe("research-out\n\nwriter-out\n\neditor-out")
        ->and($history['steps'])->toHaveCount(3);
});

test('durable top level parallel collect failures waits for branch terminal states before failing', function () {
    $response = FakeParallelFailingSwarm::make()->dispatchDurable('parallel-durable-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel') as $branch) {
        (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
    }

    expect($manager->find($runId)['status'])->toBe('pending');

    (new AdvanceDurableSwarm($runId, 3))->handle($manager);

    $history = app(SwarmHistory::class)->find($runId);
    $context = app(ContextStore::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('failed')
        ->and($history['status'])->toBe('failed')
        ->and($context['metadata']['durable_parallel_branches'])->toHaveCount(3);
});

test('durable top level parallel partial success continues with completed branch outputs', function () {
    $response = FakeParallelPartialSuccessSwarm::make()->dispatchDurable('parallel-durable-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel') as $branch) {
        (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
    }

    (new AdvanceDurableSwarm($runId, 3))->handle($manager);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['output'])->toBe("research-out\n\nwriter-out")
        ->and($history['context']['metadata']['durable_parallel_branches'])->toHaveCount(3);
});

test('durable branch queues use config and per swarm routing overrides', function () {
    config()->set('queue.connections.branch-config', ['driver' => 'null']);
    config()->set('queue.connections.branch-connection', ['driver' => 'null']);
    config()->set('swarm.durable.parallel.queue.connection', 'branch-config');
    config()->set('swarm.durable.parallel.queue.name', 'branch-config-queue');

    $configured = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($configured, 0))->handle($manager);

    expect(app(DurableRunStore::class)->branchesFor($configured, 'parallel')[0])
        ->toHaveKey('queue_connection', 'branch-config')
        ->toHaveKey('queue_name', 'branch-config-queue');

    $routed = FakeRoutedParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
    (new AdvanceDurableSwarm($routed, 0))->handle($manager);

    expect(app(DurableRunStore::class)->branchesFor($routed, 'parallel')[0])
        ->toHaveKey('queue_connection', 'branch-connection')
        ->toHaveKey('queue_name', 'branch-queue');
});

test('dispatch durable fails clearly when persistence is not database backed', function () {
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable('queued-task'))
        ->toThrow(SwarmException::class, 'Durable execution requires database-backed swarm persistence and the durable runtime table.');
});

test('dispatch durable fails when active runtime context persistence is disabled', function () {
    config()->set('swarm.capture.active_context', false);

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable('durable-task'))
        ->toThrow(SwarmException::class, 'Queued and durable swarms require active runtime context persistence so workers can continue or recover the run.');

    expect(DB::table('swarm_run_histories')->count())->toBe(0)
        ->and(DB::table('swarm_contexts')->count())->toBe(0)
        ->and(DB::table('swarm_durable_runs')->count())->toBe(0);
});

test('dispatch durable rejects explicit run contexts that exceed configured input payload limits before writing state', function () {
    config()->set('swarm.limits.max_input_bytes', 80);

    $context = RunContext::from([
        'input' => 'tiny',
        'metadata' => ['large' => str_repeat('x', 120)],
    ], 'oversized-durable-context-run-id');

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable($context))
        ->toThrow(SwarmException::class, 'Swarm input payload is');

    expect(DB::table('swarm_run_histories')->count())->toBe(0)
        ->and(DB::table('swarm_contexts')->count())->toBe(0)
        ->and(DB::table('swarm_durable_runs')->count())->toBe(0);
});

test('dispatch durable rejects oversized explicit run contexts even when overflow truncation is enabled', function () {
    config()->set('swarm.limits.max_input_bytes', 80);
    config()->set('swarm.limits.overflow', 'truncate');

    $context = RunContext::from([
        'input' => 'tiny',
        'metadata' => ['large' => str_repeat('x', 120)],
    ], 'oversized-truncated-durable-context-run-id');

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable($context))
        ->toThrow(SwarmException::class, 'Swarm input payload is');

    expect(DB::table('swarm_run_histories')->count())->toBe(0)
        ->and(DB::table('swarm_contexts')->count())->toBe(0)
        ->and(DB::table('swarm_durable_runs')->count())->toBe(0);
});

test('dispatch durable rejects invalid step timeout before writing state', function (int $stepTimeout) {
    config()->set('swarm.durable.step_timeout', $stepTimeout);

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable('durable-task'))
        ->toThrow(SwarmException::class, 'Durable swarm step timeout must be a positive integer.');

    expect(DB::table('swarm_run_histories')->count())->toBe(0)
        ->and(DB::table('swarm_contexts')->count())->toBe(0)
        ->and(DB::table('swarm_durable_runs')->count())->toBe(0);
})->with([0, -1]);

test('durable sequential swarms complete one step per job', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;

    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));

    FakeResearcher::assertPrompted('durable-task');
    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();

    $run = app(DurableSwarmManager::class)->find($runId);
    $history = app(SwarmHistory::class)->find($runId);

    expect($run['status'])->toBe('pending')
        ->and($run['next_step_index'])->toBe(1)
        ->and($history['status'])->toBe('pending')
        ->and($history['steps'])->toHaveCount(1);

    (new AdvanceDurableSwarm($runId, 1))->handle(app(DurableSwarmManager::class));

    FakeWriter::assertPrompted('research-out');
    expect(app(SwarmHistory::class)->find($runId)['steps'])->toHaveCount(2);

    (new AdvanceDurableSwarm($runId, 2))->handle(app(DurableSwarmManager::class));

    FakeEditor::assertPrompted('writer-out');

    $run = app(DurableSwarmManager::class)->find($runId);
    $history = app(SwarmHistory::class)->find($runId);

    expect($run['status'])->toBe('completed')
        ->and($history['status'])->toBe('completed')
        ->and($history['output'])->toBe('editor-out')
        ->and($history['steps'])->toHaveCount(3);
});

test('durable hierarchical swarms persist the validated route cursor before worker execution', function () {
    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('writer_node', [
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

    $response = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    FakeHierarchicalCoordinator::assertPrompted('durable-hierarchical-task');
    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();

    $run = $manager->find($runId);
    $context = app(ContextStore::class)->find($runId);
    $cursor = $run['route_cursor'];

    expect($run['next_step_index'])->toBe(1)
        ->and($cursor['current_node_id'])->toBe('writer_node')
        ->and($run['execution_mode'])->toBe('durable')
        ->and($run['route_start_node_id'])->toBe('writer_node')
        ->and($run['current_node_id'])->toBe('writer_node')
        ->and($run['completed_node_ids'])->toBe([])
        ->and($run['node_states']['coordinator']['status'])->toBe('completed')
        ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $runId)->count())->toBe(0)
        ->and($context['metadata']['route_plan_start'])->toBe('writer_node');
});

test('durable hierarchical swarms execute one routed worker per advancement', function () {
    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('writer_node', [
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

    $response = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    FakeWriter::assertPrompted('writer-task');
    FakeEditor::assertNeverPrompted();

    $context = app(ContextStore::class)->find($runId);
    expect($manager->find($runId)['status'])->toBe('pending')
        ->and($manager->find($runId)['next_step_index'])->toBe(2)
        ->and($manager->find($runId)['route_cursor']['current_node_id'])->toBe('editor_node')
        ->and($manager->find($runId)['current_node_id'])->toBe('editor_node')
        ->and($manager->find($runId)['completed_node_ids'])->toBe(['writer_node'])
        ->and($manager->find($runId)['node_states']['writer_node']['status'])->toBe('completed')
        ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $runId)->where('node_id', 'writer_node')->value('output'))->toBe('writer-out')
        ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $runId)->count())->toBe(1)
        ->and(Schema::hasColumn('swarm_durable_runs', 'node_outputs'))->toBeFalse()
        ->and($context['data'])->not->toHaveKey('hierarchical_node_outputs');

    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    FakeEditor::assertPrompted(<<<'PROMPT'
editor-task

Named outputs:
[draft]
writer-out
PROMPT);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['status'])->toBe('completed')
        ->and($history['output'])->toBe('editor-out')
        ->and($history['steps'])->toHaveCount(3)
        ->and($history['context']['metadata']['executed_node_ids'])->toBe(['writer_node', 'editor_node']);
});

test('durable hierarchical swarms aggregate usage and redact terminal cursor state from history', function () {
    config()->set('swarm.capture.outputs', false);

    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
            ],
        ]),
    ]);

    $response = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    $history = app(SwarmHistory::class)->find($runId);

    expect($history['status'])->toBe('completed')
        ->and($history['output'])->toBe('[redacted]')
        ->and($history['context']['data'])->not->toHaveKey('durable_hierarchical_cursor')
        ->and($history['context']['data'])->not->toHaveKey('hierarchical_node_outputs')
        ->and($history['usage'])->not->toBe([]);
});

test('durable hierarchical swarms fan out parallel branches and join with group metadata', function () {
    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('parallel_node', [
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

    $response = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    FakeWriter::assertNeverPrompted();
    expect($manager->find($runId)['route_cursor']['current_node_id'])->toBe('parallel_node');

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    $branches = app(DurableRunStore::class)->branchesFor($runId, 'parallel_node');

    expect($manager->find($runId)['status'])->toBe('waiting')
        ->and($branches)->toHaveCount(2);

    foreach ($branches as $branch) {
        (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
    }

    FakeWriter::assertPrompted('writer-branch');
    FakeEditor::assertPrompted('editor-branch');

    expect($manager->find($runId)['status'])->toBe('pending')
        ->and($manager->find($runId)['next_step_index'])->toBe(3);

    (new AdvanceDurableSwarm($runId, 3))->handle($manager);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['output'])->toBe('editor-out')
        ->and($history['steps'][1]['metadata']['parent_parallel_node_id'])->toBe('parallel_node')
        ->and($history['steps'][2]['metadata']['parent_parallel_node_id'])->toBe('parallel_node')
        ->and($history['context']['metadata']['parallel_groups'])->toBe([
            ['node_id' => 'parallel_node', 'branches' => ['writer_node', 'editor_node']],
        ])
        ->and($history['context']['metadata']['executed_node_ids'])->toBe(['parallel_node', 'writer_node', 'editor_node', 'finish_node']);
});

test('durable hierarchical recovery reruns the same worker after a crash before checkpoint', function () {
    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('writer_node', [
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

    $manager = app(DurableSwarmManager::class);
    $response = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task');
    $runId = $response->runId;

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    $manager->beforeStepCheckpointForTesting(function (): void {
        throw new RuntimeException('Simulated crash before checkpoint.');
    });

    expect(fn () => (new AdvanceDurableSwarm($runId, 1))->handle($manager))
        ->toThrow(RuntimeException::class, 'Simulated crash before checkpoint.');

    $manager->beforeStepCheckpointForTesting(null);

    $run = $manager->find($runId);

    expect($run['status'])->toBe('running')
        ->and($run['next_step_index'])->toBe(1)
        ->and($run['route_cursor']['current_node_id'])->toBe('writer_node')
        ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $runId)->count())->toBe(0)
        ->and(DB::table('swarm_artifacts')->where('run_id', $runId)->count())->toBe(1);

    expireDurableLease($runId);

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['output'])->toBe('editor-out')
        ->and($history['steps'])->toHaveCount(3)
        ->and(DB::table('swarm_artifacts')->where('run_id', $runId)->count())->toBe(3)
        ->and(DB::table('swarm_artifacts')->where('run_id', $runId)->where('step_agent_class', FakeWriter::class)->count())->toBe(1);
});

test('durable hierarchical checkpoint rolls back cursor and outputs when lease is lost', function () {
    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('writer_node', [
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

    $manager = app(DurableSwarmManager::class);
    $response = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task');
    $runId = $response->runId;

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    $manager->beforeStepCheckpointForTesting(function () use ($runId): void {
        stealDurableLease($runId);
    });

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    $run = $manager->find($runId);
    $context = app(ContextStore::class)->find($runId);

    expect($run['status'])->toBe('running')
        ->and($run['next_step_index'])->toBe(1)
        ->and($run['route_cursor']['current_node_id'])->toBe('writer_node')
        ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $runId)->count())->toBe(0)
        ->and(DB::table('swarm_artifacts')->where('run_id', $runId)->count())->toBe(1)
        ->and($context['metadata']['current_node_id'])->toBe('writer_node');
});

test('durable hierarchical completion rolls back terminal scrub when terminal context persistence fails', function () {
    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
            ],
        ]),
    ]);

    $response = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    app()->instance(ContextStore::class, new class(app('db')->connection(), app('config')) extends DatabaseContextStore
    {
        public function put(RunContext $context, int $ttlSeconds): void
        {
            parent::put($context, $ttlSeconds);

            throw new RuntimeException('Simulated terminal context persistence failure.');
        }
    });
    app()->forgetInstance(DurableRunRecorder::class);

    app()->forgetInstance(DurableSwarmManager::class);

    expect(fn () => (new AdvanceDurableSwarm($runId, 1))->handle(app(DurableSwarmManager::class)))
        ->toThrow(RuntimeException::class, 'Simulated terminal context persistence failure.');

    $run = app(DurableSwarmManager::class)->find($runId);
    $history = app(SwarmHistory::class)->find($runId);

    expect($run['status'])->toBe('running')
        ->and($run['route_plan'])->not->toBeNull()
        ->and($run['route_cursor'])->not->toBeNull()
        ->and($history['status'])->toBe('running')
        ->and(DB::table('swarm_artifacts')->where('run_id', $runId)->where('step_agent_class', FakeWriter::class)->count())->toBe(0);
});

test('durable hierarchical workers load only requested node outputs', function () {
    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('writer_node', [
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

    $store = new class(app('db')->connection(), app('config')) extends DatabaseDurableRunStore
    {
        /** @var array<int, array<int, string>> */
        public array $requestedNodeIds = [];

        public function hierarchicalNodeOutputsFor(string $runId, array $nodeIds): array
        {
            $this->requestedNodeIds[] = array_values($nodeIds);

            return parent::hierarchicalNodeOutputsFor($runId, $nodeIds);
        }
    };

    app()->instance(DurableRunStore::class, $store);
    app()->forgetInstance(DurableRunRecorder::class);

    app()->forgetInstance(DurableSwarmManager::class);
    app()->forgetInstance(SwarmRunner::class);

    $manager = app(DurableSwarmManager::class);
    $response = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task');
    $runId = $response->runId;

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    DB::table('swarm_durable_node_outputs')->insert([
        'run_id' => $runId,
        'node_id' => 'unused_node',
        'output' => str_repeat('x', 2048),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    expect($store->requestedNodeIds)->toContain([])
        ->and($store->requestedNodeIds)->toContain(['writer_node']);
});

test('durable hierarchical terminal states retain neutral route state and scrub node output rows', function () {
    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'metadata' => ['sensitive' => 'writer-metadata'],
                'next' => 'finish_node',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output' => 'literal-final-output',
            ],
        ]),
    ]);

    $manager = app(DurableSwarmManager::class);
    $completed = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task')->runId;

    (new AdvanceDurableSwarm($completed, 0))->handle($manager);

    expect($manager->find($completed)['route_plan']['nodes']['writer_node'])
        ->toHaveKey('prompt', 'writer-task');

    (new AdvanceDurableSwarm($completed, 1))->handle($manager);

    expect($manager->find($completed)['status'])->toBe('completed')
        ->and($manager->find($completed)['route_plan'])->not->toBeNull()
        ->and($manager->find($completed)['route_plan']['nodes']['writer_node'])->not->toHaveKeys(['prompt', 'metadata'])
        ->and($manager->find($completed)['route_plan']['nodes']['finish_node'])->not->toHaveKey('output')
        ->and($manager->find($completed)['route_cursor'])->not->toBeNull()
        ->and($manager->find($completed)['route_start_node_id'])->toBe('writer_node')
        ->and($manager->find($completed)['completed_node_ids'])->toBe(['writer_node'])
        ->and($manager->find($completed)['node_states']['writer_node']['status'])->toBe('completed')
        ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $completed)->count())->toBe(0);

    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'metadata' => ['sensitive' => 'cancel-metadata'],
            ],
        ]),
    ]);

    $cancelled = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-cancel')->runId;

    (new AdvanceDurableSwarm($cancelled, 0))->handle($manager);

    DB::table('swarm_durable_node_outputs')->insert([
        'run_id' => $cancelled,
        'node_id' => 'writer_node',
        'output' => 'cancel-output',
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager->cancel($cancelled);

    expect($manager->find($cancelled)['status'])->toBe('cancelled')
        ->and($manager->find($cancelled)['route_plan'])->not->toBeNull()
        ->and($manager->find($cancelled)['route_plan']['nodes']['writer_node'])->not->toHaveKeys(['prompt', 'metadata'])
        ->and($manager->find($cancelled)['route_cursor'])->not->toBeNull()
        ->and($manager->find($cancelled)['cancelled_at'])->not->toBeNull()
        ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $cancelled)->count())->toBe(0);

    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'writer-task',
                'metadata' => ['sensitive' => 'failed-metadata'],
            ],
        ]),
    ]);

    FakeWriter::fake(function (): string {
        throw new RuntimeException('Hierarchical worker failed.');
    });

    $failed = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-fail')->runId;

    (new AdvanceDurableSwarm($failed, 0))->handle($manager);

    DB::table('swarm_durable_node_outputs')->insert([
        'run_id' => $failed,
        'node_id' => 'writer_node',
        'output' => 'failed-output',
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => (new AdvanceDurableSwarm($failed, 1))->handle($manager))
        ->toThrow(RuntimeException::class, 'Hierarchical worker failed.');

    expect($manager->find($failed)['status'])->toBe('failed')
        ->and($manager->find($failed)['route_plan'])->not->toBeNull()
        ->and($manager->find($failed)['route_plan']['nodes']['writer_node'])->not->toHaveKeys(['prompt', 'metadata'])
        ->and($manager->find($failed)['route_cursor'])->not->toBeNull()
        ->and($manager->find($failed)['failure']['message'])->toBe('Hierarchical worker failed.')
        ->and($manager->find($failed)['node_states']['writer_node']['status'])->toBe('failed')
        ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $failed)->count())->toBe(0);
});

test('durable hierarchical swarms reject invalid plans before worker execution', function () {
    FakeHierarchicalCoordinator::fake([
        durableHierarchicalPlan('writer_node', [
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

    $response = FakeHierarchicalLimitedSwarm::make()->dispatchDurable('durable-hierarchical-task');
    $runId = $response->runId;

    expect(fn () => app(DurableSwarmManager::class)->advance($runId, 0))
        ->toThrow(SwarmException::class, FakeHierarchicalLimitedSwarm::class.": hierarchical route plan requires 3 agent executions but the swarm allows 2. Increase #[MaxAgentSteps] or reduce the plan's worker nodes.");

    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('failed');
});

test('durable advance rejects invalid persisted step timeout before lease acquisition', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;

    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update(['step_timeout_seconds' => 0]);

    expect(fn () => app(DurableSwarmManager::class)->advance($runId, 0))
        ->toThrow(SwarmException::class, 'Durable swarm step timeout must be a positive integer.');

    expect(DB::table('swarm_durable_runs')->where('run_id', $runId)->value('execution_token'))->toBeNull();
});

test('durable terminal completion overwrites persisted context with redacted capture snapshot', function () {
    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);

    $response = FakeSequentialSwarm::make()->dispatchDurable('sensitive-durable-task');
    $runId = $response->runId;

    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    expect(app(ContextStore::class)->find($runId)['input'])->toBe('sensitive-durable-task');

    (new AdvanceDurableSwarm($runId, 1))->handle(app(DurableSwarmManager::class));
    (new AdvanceDurableSwarm($runId, 2))->handle(app(DurableSwarmManager::class));

    $context = app(ContextStore::class)->find($runId);
    $history = app(SwarmHistory::class)->find($runId);

    expect($context['input'])->toBe('[redacted]');
    expect($context['data'])->toBe(['input' => '[redacted]']);
    expect($history['output'])->toBe('[redacted]');
    expect($history['context']['input'])->toBe('[redacted]');
});

test('durable failures redact persisted and event messages when capture is disabled', function () {
    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);
    Event::fake([SwarmFailed::class]);

    $response = FailingQueuedSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;

    expect(fn () => app(DurableSwarmManager::class)->advance($runId, 0))
        ->toThrow(RuntimeException::class, 'Queued swarm failed.');

    $history = app(SwarmHistory::class)->find($runId);
    $run = app(DurableSwarmManager::class)->find($runId);

    expect($history['error'])->toMatchArray([
        'class' => RuntimeException::class,
        'message' => '[redacted]',
    ]);
    expect($run['failure'])->toMatchArray([
        'class' => RuntimeException::class,
        'message' => '[redacted]',
    ]);
    expect($run['node_states']['step:0']['failure'])->toMatchArray([
        'class' => RuntimeException::class,
        'message' => '[redacted]',
    ]);
    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $event) => $event->runId === $runId
        && $event->exception->getMessage() === '[redacted]');
});

test('durable workers stop cleanly when lease ownership is lost before context persistence', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;
    $contextStore = app(ContextStore::class);

    app()->instance(ContextStore::class, new class($contextStore, $runId) implements ContextStore
    {
        public function __construct(
            protected ContextStore $inner,
            protected string $runId,
        ) {}

        public function put(RunContext $context, int $ttlSeconds): void
        {
            stealDurableLease($this->runId);
            $this->inner->put($context, $ttlSeconds);
        }

        public function find(string $runId): ?array
        {
            return $this->inner->find($runId);
        }
    });
    app()->forgetInstance(DurableRunRecorder::class);
    app()->forgetInstance(DurableSwarmManager::class);

    Event::fake([SwarmCompleted::class]);

    $manager = app(DurableSwarmManager::class);
    $manager->advance($runId, 0);

    $run = $manager->find($runId);
    $history = app(SwarmHistory::class)->find($runId);

    expect($run['status'])->toBe('running')
        ->and($run['next_step_index'])->toBe(0)
        ->and($history['status'])->toBe('running')
        ->and($history['steps'])->toHaveCount(1)
        ->and(DB::table('swarm_artifacts')->where('run_id', $runId)->count())->toBe(0);

    Event::assertNotDispatched(SwarmCompleted::class, fn (SwarmCompleted $event) => $event->runId === $runId);
    FakeWriter::assertNeverPrompted();
});

test('durable workers stop cleanly when the lease expires before context persistence', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;
    $contextStore = app(ContextStore::class);

    app()->instance(ContextStore::class, new class($contextStore, $runId) implements ContextStore
    {
        public function __construct(
            protected ContextStore $inner,
            protected string $runId,
        ) {}

        public function put(RunContext $context, int $ttlSeconds): void
        {
            expireDurableLease($this->runId);
            $this->inner->put($context, $ttlSeconds);
        }

        public function find(string $runId): ?array
        {
            return $this->inner->find($runId);
        }
    });
    app()->forgetInstance(DurableRunRecorder::class);
    app()->forgetInstance(DurableSwarmManager::class);

    Event::fake([SwarmCompleted::class]);

    $manager = app(DurableSwarmManager::class);
    $manager->advance($runId, 0);

    $run = $manager->find($runId);
    $history = app(SwarmHistory::class)->find($runId);

    expect($run['status'])->toBe('running')
        ->and($run['next_step_index'])->toBe(0)
        ->and($history['status'])->toBe('running')
        ->and($history['steps'])->toHaveCount(1)
        ->and(DB::table('swarm_artifacts')->where('run_id', $runId)->count())->toBe(0);

    Event::assertNotDispatched(SwarmCompleted::class, fn (SwarmCompleted $event) => $event->runId === $runId);
    FakeWriter::assertNeverPrompted();
});

test('durable workers stop cleanly when lease ownership is lost before next step release', function () {
    ensureJobsTableExists();

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;
    $artifacts = app(ArtifactRepository::class);

    app()->instance(ArtifactRepository::class, new class($artifacts, $runId) implements ArtifactRepository
    {
        public function __construct(
            protected ArtifactRepository $inner,
            protected string $runId,
        ) {}

        public function storeMany(string $runId, array $artifacts, int $ttlSeconds): void
        {
            $this->inner->storeMany($runId, $artifacts, $ttlSeconds);
            stealDurableLease($this->runId);
        }

        public function all(string $runId): array
        {
            return $this->inner->all($runId);
        }
    });
    app()->forgetInstance(DurableRunRecorder::class);
    app()->forgetInstance(DurableSwarmManager::class);

    Event::fake([SwarmCompleted::class]);

    $manager = app(DurableSwarmManager::class);
    $manager->advance($runId, 0);

    $run = $manager->find($runId);
    $history = app(SwarmHistory::class)->find($runId);

    expect($run['status'])->toBe('running')
        ->and($run['next_step_index'])->toBe(0)
        ->and($history['status'])->toBe('running')
        ->and($history['steps'])->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0);

    Event::assertNotDispatched(SwarmCompleted::class, fn (SwarmCompleted $event) => $event->runId === $runId);
    FakeWriter::assertNeverPrompted();
});

test('durable recovery lets a reclaimed owner complete after a stale worker becomes inert', function () {
    $stolen = false;
    $runId = null;

    FakeResearcher::fake(function () use (&$stolen, &$runId) {
        if (! $stolen && $runId !== null) {
            stealDurableLease($runId);
            $stolen = true;
        }

        return 'research-out';
    });

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);
    $manager->advance($runId, 0);

    expect($manager->find($runId)['status'])->toBe('running');

    $staleUpdatedAt = now()->subMinutes(10);

    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update([
            'status' => 'pending',
            'execution_token' => null,
            'leased_until' => null,
            'updated_at' => $staleUpdatedAt,
        ]);

    $beforeRecoveryScan = $manager->find($runId);

    app(DurableRunStore::class)->recoverable();

    expect($manager->find($runId)['recovery_count'])->toBe($beforeRecoveryScan['recovery_count'])
        ->and($manager->find($runId)['last_recovered_at'])->toBe($beforeRecoveryScan['last_recovered_at'])
        ->and($manager->find($runId)['updated_at'])->toBe($beforeRecoveryScan['updated_at']);

    Artisan::call('swarm:recover');

    expect($manager->find($runId)['recovery_count'])->toBe(1)
        ->and($manager->find($runId)['last_recovered_at'])->not->toBeNull();

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['status'])->toBe('completed')
        ->and($history['steps'])->toHaveCount(3)
        ->and($history['output'])->toBe('editor-out');
});

test('durable recovery markers advance only after redispatch succeeds', function () {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('durable-task')->runId;
    $staleUpdatedAt = now()->subMinutes(10);

    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update([
            'status' => 'pending',
            'execution_token' => null,
            'leased_until' => null,
            'updated_at' => $staleUpdatedAt,
        ]);

    $manager = app(DurableSwarmManager::class);
    $before = $manager->find($runId);

    $throwingManager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class)) extends DurableSwarmManager
    {
        public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            throw new RuntimeException('Redispatch failed.');
        }
    };

    expect(fn () => $throwingManager->recover(runId: $runId))
        ->toThrow(RuntimeException::class, 'Redispatch failed.');

    $after = $manager->find($runId);

    expect($after['recovery_count'])->toBe($before['recovery_count'])
        ->and($after['last_recovered_at'])->toBe($before['last_recovered_at'])
        ->and($after['updated_at'])->toBe($before['updated_at']);
});

test('durable recovery markers do not mutate terminal runs from stale recovery results', function () {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('durable-task')->runId;
    $staleUpdatedAt = now()->subMinutes(10);

    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update([
            'status' => 'pending',
            'execution_token' => null,
            'leased_until' => null,
            'updated_at' => $staleUpdatedAt,
        ]);

    $store = app(DurableRunStore::class);
    $recoverable = $store->recoverable(runId: $runId);

    expect($recoverable)->toHaveCount(1);

    $terminalAt = now();

    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update([
            'status' => 'completed',
            'finished_at' => $terminalAt,
            'updated_at' => $terminalAt,
        ]);

    $before = app(DurableSwarmManager::class)->find($runId);

    $store->markRecoveryDispatched($recoverable[0]['run_id']);

    $after = app(DurableSwarmManager::class)->find($runId);

    expect($after['recovery_count'])->toBe($before['recovery_count'])
        ->and($after['last_recovered_at'])->toBe($before['last_recovered_at'])
        ->and($after['updated_at'])->toBe($before['updated_at']);
});

test('dispatch durable remains lazy until the response is released', function () {
    ensureJobsTableExists();

    config()->set('queue.connections.durable-test', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('pending');

    unset($response);
    gc_collect_cycles();

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->latest('id')->first())->not->toBeNull();
});

test('dispatch durable fails without leaving persisted state behind when the durable table is missing', function () {
    Schema::drop('swarm_durable_runs');

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable('durable-task'))
        ->toThrow(SwarmException::class, 'Database-backed durable swarms require the [swarm_durable_runs] table.');

    expect(DB::table('swarm_run_histories')->count())->toBe(0)
        ->and(DB::table('swarm_contexts')->count())->toBe(0)
        ->and(DB::table('swarm_artifacts')->count())->toBe(0);
});

test('dispatch durable fails without leaving persisted state behind when the context table is missing', function () {
    Schema::drop('swarm_contexts');

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable('durable-task'))
        ->toThrow(SwarmException::class, 'Database-backed durable swarms require the [swarm_contexts] table.');

    expect(DB::table('swarm_run_histories')->count())->toBe(0)
        ->and(Schema::hasTable('swarm_durable_runs'))->toBeTrue()
        ->and(DB::table('swarm_durable_runs')->count())->toBe(0)
        ->and(DB::table('swarm_artifacts')->count())->toBe(0);
});

test('dispatch durable fails without leaving persisted state behind when the history table is missing', function () {
    Schema::drop('swarm_run_histories');

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable('durable-task'))
        ->toThrow(SwarmException::class, 'Database-backed durable swarms require the [swarm_run_histories] table.');

    expect(Schema::hasTable('swarm_contexts'))->toBeTrue()
        ->and(DB::table('swarm_contexts')->count())->toBe(0)
        ->and(Schema::hasTable('swarm_durable_runs'))->toBeTrue()
        ->and(DB::table('swarm_durable_runs')->count())->toBe(0)
        ->and(DB::table('swarm_artifacts')->count())->toBe(0);
});

test('dispatch durable rolls startup writes back when a later startup write fails', function () {
    app()->instance(ContextStore::class, new class(app('db')->connection(), app('config')) extends DatabaseContextStore
    {
        public function put(RunContext $context, int $ttlSeconds): void
        {
            parent::put($context, $ttlSeconds);

            throw new RuntimeException('Simulated context persistence failure after startup write.');
        }
    });
    app()->forgetInstance(DurableRunRecorder::class);
    app()->forgetInstance(DurableSwarmManager::class);
    app()->forgetInstance(SwarmRunner::class);

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable('durable-task'))
        ->toThrow(RuntimeException::class, 'Simulated context persistence failure after startup write.');

    expect(DB::table('swarm_durable_runs')->count())->toBe(0)
        ->and(DB::table('swarm_run_histories')->count())->toBe(0)
        ->and(DB::table('swarm_contexts')->count())->toBe(0)
        ->and(DB::table('swarm_artifacts')->count())->toBe(0);
});

test('dispatch durable fails without leaving persisted state behind when required durable columns are missing', function () {
    dropColumnIfPresent(Schema::getConnection()->getSchemaBuilder(), 'swarm_durable_runs', 'execution_token');

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable('durable-task'))
        ->toThrow(SwarmException::class, 'Database-backed durable swarms require runtime columns on [swarm_durable_runs] for lease ownership and recovery.');

    expect(DB::table('swarm_run_histories')->count())->toBe(0)
        ->and(DB::table('swarm_contexts')->count())->toBe(0)
        ->and(DB::table('swarm_artifacts')->count())->toBe(0);
});

test('durable pause resume and cancel commands update runtime state', function () {
    Event::fake([SwarmPaused::class, SwarmResumed::class, SwarmCancelled::class]);

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;

    Artisan::call('swarm:pause', ['runId' => $runId]);

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('paused')
        ->and(app(DurableSwarmManager::class)->find($runId)['paused_at'])->not->toBeNull()
        ->and(app(DurableSwarmManager::class)->find($runId)['pause_requested_at'])->not->toBeNull();
    expect(app(SwarmHistory::class)->find($runId)['status'])->toBe('paused');
    Event::assertDispatched(SwarmPaused::class, fn (SwarmPaused $event) => $event->runId === $runId);

    Artisan::call('swarm:resume', ['runId' => $runId]);

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('pending')
        ->and(app(DurableSwarmManager::class)->find($runId)['resumed_at'])->not->toBeNull()
        ->and(app(DurableSwarmManager::class)->find($runId)['pause_requested_at'])->toBeNull();
    expect(app(SwarmHistory::class)->find($runId)['status'])->toBe('pending');
    Event::assertDispatched(SwarmResumed::class, fn (SwarmResumed $event) => $event->runId === $runId);

    Artisan::call('swarm:cancel', ['runId' => $runId]);

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('cancelled')
        ->and(app(DurableSwarmManager::class)->find($runId)['cancelled_at'])->not->toBeNull()
        ->and(app(DurableSwarmManager::class)->find($runId)['cancel_requested_at'])->not->toBeNull();
    expect(app(SwarmHistory::class)->find($runId)['status'])->toBe('cancelled');
    Event::assertDispatched(SwarmCancelled::class, fn (SwarmCancelled $event) => $event->runId === $runId);
});

test('durable pause rolls runtime state back when history sync fails', function () {
    Event::fake([SwarmPaused::class]);

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;

    app()->instance(DatabaseRunHistoryStore::class, new class(app('db')->connection(), app('config'), app(SwarmCapture::class)) extends DatabaseRunHistoryStore
    {
        public function syncDurableState(string $runId, string $status, RunContext $context, array $metadata, int $ttlSeconds, bool $finished, ?string $executionToken = null, ?int $leaseSeconds = null): void
        {
            parent::syncDurableState($runId, $status, $context, $metadata, $ttlSeconds, $finished, $executionToken, $leaseSeconds);

            throw new RuntimeException('Simulated pause history sync failure.');
        }
    });
    app()->forgetInstance(DurableRunRecorder::class);
    app()->forgetInstance(DurableSwarmManager::class);

    expect(fn () => app(DurableSwarmManager::class)->pause($runId))
        ->toThrow(RuntimeException::class, 'Simulated pause history sync failure.');

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('pending');
    expect(app(SwarmHistory::class)->find($runId)['status'])->toBe('pending');
    Event::assertNotDispatched(SwarmPaused::class, fn (SwarmPaused $event) => $event->runId === $runId);
});

test('durable resume rolls runtime state back when history sync fails', function () {
    Event::fake([SwarmResumed::class]);

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;

    app(DurableSwarmManager::class)->pause($runId);

    app()->instance(DatabaseRunHistoryStore::class, new class(app('db')->connection(), app('config'), app(SwarmCapture::class)) extends DatabaseRunHistoryStore
    {
        public function syncDurableState(string $runId, string $status, RunContext $context, array $metadata, int $ttlSeconds, bool $finished, ?string $executionToken = null, ?int $leaseSeconds = null): void
        {
            parent::syncDurableState($runId, $status, $context, $metadata, $ttlSeconds, $finished, $executionToken, $leaseSeconds);

            throw new RuntimeException('Simulated resume history sync failure.');
        }
    });
    app()->forgetInstance(DurableRunRecorder::class);
    app()->forgetInstance(DurableSwarmManager::class);

    expect(fn () => app(DurableSwarmManager::class)->resume($runId))
        ->toThrow(RuntimeException::class, 'Simulated resume history sync failure.');

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('paused');
    expect(app(SwarmHistory::class)->find($runId)['status'])->toBe('paused');
    Event::assertNotDispatched(SwarmResumed::class, fn (SwarmResumed $event) => $event->runId === $runId);
});

test('in flight durable pause rolls runtime state back when history sync fails', function () {
    Event::fake([SwarmPaused::class]);

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;

    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update([
            'pause_requested_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

    app()->instance(DatabaseRunHistoryStore::class, new class(app('db')->connection(), app('config'), app(SwarmCapture::class)) extends DatabaseRunHistoryStore
    {
        public function syncDurableState(string $runId, string $status, RunContext $context, array $metadata, int $ttlSeconds, bool $finished, ?string $executionToken = null, ?int $leaseSeconds = null): void
        {
            parent::syncDurableState($runId, $status, $context, $metadata, $ttlSeconds, $finished, $executionToken, $leaseSeconds);

            if ($status === 'paused') {
                throw new RuntimeException('Simulated in-flight pause history sync failure.');
            }
        }
    });
    app()->forgetInstance(DurableRunRecorder::class);
    app()->forgetInstance(DurableSwarmManager::class);

    expect(fn () => (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class)))
        ->toThrow(RuntimeException::class, 'Simulated in-flight pause history sync failure.');

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('running');
    expect(app(SwarmHistory::class)->find($runId)['status'])->toBe('running');
    Event::assertNotDispatched(SwarmPaused::class, fn (SwarmPaused $event) => $event->runId === $runId);
});

test('durable recovery can resume after checkpoint persistence before next dispatch', function () {
    $manager = app(DurableSwarmManager::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;

    $manager->afterStepCheckpointForTesting(function (): void {
        throw new RuntimeException('Simulated crash after checkpoint.');
    });

    expect(fn () => (new AdvanceDurableSwarm($runId, 0))->handle($manager))
        ->toThrow(RuntimeException::class, 'Simulated crash after checkpoint.');

    $manager->afterStepCheckpointForTesting(null);

    $run = $manager->find($runId);
    $history = app(SwarmHistory::class)->find($runId);

    expect($run['status'])->toBe('pending')
        ->and($run['next_step_index'])->toBe(1)
        ->and($history['status'])->toBe('pending')
        ->and($history['steps'])->toHaveCount(1);

    Artisan::call('swarm:recover');

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('completed');
    expect(app(SwarmHistory::class)->find($runId)['output'])->toBe('editor-out');
});

test('duplicate durable step jobs do not double run a step', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    FakeWriter::assertPrompted('research-out');
    expect(app(SwarmHistory::class)->find($runId)['steps'])->toHaveCount(3);
});
