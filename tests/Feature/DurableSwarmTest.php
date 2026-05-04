<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmChildCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Events\SwarmSignalled;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurablePayloadCapture;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRetryHandler;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRunContext;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRunInspector;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSignalHandler;
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
use BuiltByBerry\LaravelSwarm\Support\SwarmWebhooks;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyDurableAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ChildDispatchingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableWaitAttributeSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingParallelChildDispatchingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalLimitedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelFailingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelPartialSuccessSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRoutedParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelChildDispatchingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RetryableDurableSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RetryableParallelDurableSwarm;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Events\Dispatcher;
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

function noopPendingDispatch(): PendingDispatch
{
    return new class(new class
    {
        public function handle(): void {}
    }) extends PendingDispatch
    {

        public function __destruct() {}
    };
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

function staleWaitingRun(string $runId): void
{
    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update([
            'updated_at' => now('UTC')->subMinutes(10),
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

test('durable recovery releases waiting joins after branches checkpoint terminally', function () {
    $response = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel') as $branch) {
        DB::table('swarm_durable_branches')
            ->where('run_id', $runId)
            ->where('branch_id', $branch['branch_id'])
            ->update([
                'status' => 'completed',
                'output' => 'output-'.$branch['step_index'],
                'usage' => json_encode(['total_tokens' => (int) $branch['step_index'] + 1]),
                'duration_ms' => 1,
                'failure' => null,
                'finished_at' => now('UTC'),
                'execution_token' => null,
                'leased_until' => null,
                'updated_at' => now('UTC')->subMinutes(10),
            ]);
    }

    staleWaitingRun($runId);

    Artisan::call('swarm:recover');

    expect($manager->find($runId)['status'])->toBe('pending')
        ->and($manager->find($runId)['next_step_index'])->toBe(3)
        ->and($manager->find($runId)['recovery_count'])->toBe(1)
        ->and($manager->find($runId)['last_recovered_at'])->not->toBeNull();

    (new AdvanceDurableSwarm($runId, 3))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('completed');
    expect(app(SwarmHistory::class)->find($runId)['output'])->toBe("output-0\n\noutput-1\n\noutput-2");
});

test('durable recovery does not release waiting joins until every branch is terminal', function () {
    $response = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->where('branch_id', 'parallel:0')
        ->update([
            'status' => 'completed',
            'output' => 'output-0',
            'usage' => json_encode([]),
            'duration_ms' => 1,
            'finished_at' => now('UTC'),
            'updated_at' => now('UTC')->subMinutes(10),
        ]);

    staleWaitingRun($runId);

    Artisan::call('swarm:recover');

    expect($manager->find($runId)['status'])->toBe('waiting')
        ->and($manager->find($runId)['recovery_count'])->toBe(0);
});

test('durable recovery joins collected branch failures and fails the parent', function () {
    $response = FakeParallelFailingSwarm::make()->dispatchDurable('parallel-durable-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->where('branch_id', 'parallel:0')
        ->update([
            'status' => 'completed',
            'output' => 'research-out',
            'usage' => json_encode([]),
            'duration_ms' => 1,
            'finished_at' => now('UTC'),
            'updated_at' => now('UTC')->subMinutes(10),
        ]);
    DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->where('branch_id', 'parallel:1')
        ->update([
            'status' => 'failed',
            'failure' => json_encode(['message' => 'branch failed', 'class' => RuntimeException::class]),
            'finished_at' => now('UTC'),
            'updated_at' => now('UTC')->subMinutes(10),
        ]);
    DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->where('branch_id', 'parallel:2')
        ->update([
            'status' => 'completed',
            'output' => 'editor-out',
            'usage' => json_encode([]),
            'duration_ms' => 1,
            'finished_at' => now('UTC'),
            'updated_at' => now('UTC')->subMinutes(10),
        ]);
    staleWaitingRun($runId);

    Artisan::call('swarm:recover');
    (new AdvanceDurableSwarm($runId, 3))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('failed')
        ->and(app(SwarmHistory::class)->find($runId)['status'])->toBe('failed')
        ->and(app(ContextStore::class)->find($runId)['metadata']['durable_parallel_branches'])->toHaveCount(3);
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

test('durable branch recovery uses the configured branch table name', function () {
    Schema::rename('swarm_durable_branches', 'custom_swarm_durable_branches');
    config()->set('swarm.tables.durable_branches', 'custom_swarm_durable_branches');
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(DurableSwarmManager::class);

    $response = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    DB::table('custom_swarm_durable_branches')
        ->where('run_id', $runId)
        ->update([
            'updated_at' => now('UTC')->subMinutes(10),
            'leased_until' => null,
        ]);

    $branches = app(DurableRunStore::class)->recoverableBranches(runId: $runId);

    expect($branches)->toHaveCount(3)
        ->and($branches[0]['run_id'])->toBe($runId);
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

test('durable sequential advance dispatches next step through manager seam', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $manager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
    {
        /** @var array<int, int> */
        public array $stepDispatches = [];

        public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->stepDispatches[] = $stepIndex;

            return noopPendingDispatch();
        }
    };

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect($manager->stepDispatches)->toBe([1])
        ->and($manager->find($response->runId)['next_step_index'])->toBe(1);
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

test('durable hierarchical branch wait dispatches branches through manager seam', function () {
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
    $manager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
    {
        /** @var array<int, string> */
        public array $branchDispatches = [];

        public function dispatchBranchJob(string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->branchDispatches[] = $branchId;

            return noopPendingDispatch();
        }
    };

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);

    expect($manager->branchDispatches)->toBe(['parallel_node:writer_node', 'parallel_node:editor_node'])
        ->and($manager->find($response->runId)['status'])->toBe('waiting');
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

test('durable workers stop cleanly when lease ownership is lost before terminal completion', function () {
    Event::fake([SwarmCompleted::class]);

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    $recorder = app(DurableRunRecorder::class);

    app()->instance(DurableRunRecorder::class, new class($recorder, $runId) extends DurableRunRecorder
    {
        public function __construct(
            protected DurableRunRecorder $inner,
            protected string $runId,
        ) {}

        public function complete(string $runId, string $token, RunContext $context, SwarmResponse $capturedResponse, int $stepLeaseSeconds, ?SwarmStep $step = null): void
        {
            stealDurableLease($this->runId);

            $this->inner->complete($runId, $token, $context, $capturedResponse, $stepLeaseSeconds, $step);
        }
    });
    app()->forgetInstance(DurableSwarmManager::class);

    $manager = app(DurableSwarmManager::class);

    expect(fn () => (new AdvanceDurableSwarm($runId, 2))->handle($manager))
        ->not->toThrow(Throwable::class);

    $run = $manager->find($runId);

    expect($run['status'])->toBe('running')
        ->and($run['execution_token'])->toBe('replacement-token');

    Event::assertNotDispatched(SwarmCompleted::class, fn (SwarmCompleted $event) => $event->runId === $runId);
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

    $throwingManager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
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

test('durable recovery dispatches due retry runs only once per recovery window', function () {
    FlakyDurableAgent::reset(failuresBeforeSuccess: 1);

    $response = RetryableDurableSwarm::make()->dispatchDurable('retry-task');
    (new AdvanceDurableSwarm($response->runId, 0))->handle(app(DurableSwarmManager::class));

    $this->travel(61)->seconds();

    $manager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
    {
        /** @var array<int, int> */
        public array $stepDispatches = [];

        public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->stepDispatches[] = $stepIndex;

            return noopPendingDispatch();
        }
    };

    $manager->recover(runId: $response->runId);
    $manager->recover(runId: $response->runId);

    expect($manager->stepDispatches)->toBe([0])
        ->and($manager->find($response->runId)['next_retry_at'])->toBeNull();
});

test('durable recovery dispatches stale branches only once per recovery window', function () {
    $response = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task');
    $runId = $response->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));

    DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->where('branch_id', 'parallel:0')
        ->update([
            'status' => 'pending',
            'execution_token' => null,
            'leased_until' => null,
            'updated_at' => now('UTC')->subMinutes(10),
        ]);

    $manager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
    {
        /** @var array<int, string> */
        public array $branchDispatches = [];

        public function dispatchBranchJob(string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->branchDispatches[] = $branchId;

            return parent::dispatchBranchJob($runId, $branchId, $connection, $queue);
        }
    };

    $manager->recover(runId: $runId);
    $manager->recover(runId: $runId);

    expect($manager->branchDispatches)->toBe(['parallel:0']);
});

test('durable recovery dispatches due retry branches only once per recovery window', function () {
    FlakyDurableAgent::reset(failuresBeforeSuccess: 1);
    FakeResearcher::fake(['stable-branch']);

    $response = RetryableParallelDurableSwarm::make()->dispatchDurable('parallel-retry-task');
    $runId = $response->runId;
    $baseManager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($baseManager);
    (new AdvanceDurableBranch($runId, 'parallel:0'))->handle($baseManager);
    (new AdvanceDurableBranch($runId, 'parallel:1'))->handle($baseManager);

    $this->travel(61)->seconds();

    $manager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
    {
        /** @var array<int, string> */
        public array $branchDispatches = [];

        public function dispatchBranchJob(string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->branchDispatches[] = $branchId;

            return parent::dispatchBranchJob($runId, $branchId, $connection, $queue);
        }
    };

    $manager->recover(runId: $runId);
    $manager->recover(runId: $runId);

    expect($manager->branchDispatches)->toBe(['parallel:1'])
        ->and(app(DurableRunStore::class)->findBranch($runId, 'parallel:1')['next_retry_at'])->toBeNull();
});

test('durable recovery dispatches timed out waits only once per recovery window', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');

    $manager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
    {
        /** @var array<int, int> */
        public array $stepDispatches = [];

        public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->stepDispatches[] = $stepIndex;

            return parent::dispatchStepJob($runId, $stepIndex, $connection, $queue);
        }
    };

    $manager->wait($response->runId, 'timed_wait', 'Timed wait', 60);
    $this->travel(61)->seconds();

    $manager->recover(runId: $response->runId);
    $manager->recover(runId: $response->runId);

    expect($manager->stepDispatches)->toBe([0])
        ->and($manager->inspect($response->runId)->waits[0]['status'])->toBe('timed_out');
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

test('durable waiting runs pause immediately and resume branch work without joining early', function () {
    Event::fake([SwarmPaused::class, SwarmResumed::class]);

    $response = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task');
    $runId = $response->runId;
    $manager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
    {
        /** @var array<int, string> */
        public array $branchDispatches = [];

        /** @var array<int, int> */
        public array $stepDispatches = [];

        public function dispatchBranchJob(string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->branchDispatches[] = $branchId;

            return parent::dispatchBranchJob($runId, $branchId, $connection, $queue);
        }

        public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->stepDispatches[] = $stepIndex;

            return parent::dispatchStepJob($runId, $stepIndex, $connection, $queue);
        }
    };

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $manager->branchDispatches = [];
    $manager->stepDispatches = [];

    DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->where('branch_id', 'parallel:0')
        ->update([
            'status' => 'completed',
            'output' => 'research-out',
            'usage' => json_encode([]),
            'duration_ms' => 1,
            'finished_at' => now('UTC'),
        ]);
    DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->where('branch_id', 'parallel:2')
        ->update([
            'status' => 'running',
            'leased_until' => now('UTC')->addMinutes(5),
            'execution_token' => 'active-branch-token',
        ]);

    $manager->pause($runId);

    expect($manager->find($runId)['status'])->toBe('paused')
        ->and(app(SwarmHistory::class)->find($runId)['status'])->toBe('paused');
    Event::assertDispatched(SwarmPaused::class, fn (SwarmPaused $event) => $event->runId === $runId);

    $manager->resume($runId);

    expect($manager->find($runId)['status'])->toBe('waiting')
        ->and(app(SwarmHistory::class)->find($runId)['status'])->toBe('waiting')
        ->and($manager->branchDispatches)->toContain('parallel:1')
        ->and($manager->branchDispatches)->not->toContain('parallel:0')
        ->and($manager->branchDispatches)->not->toContain('parallel:2')
        ->and($manager->stepDispatches)->not->toContain(3);
    Event::assertDispatched(SwarmResumed::class, fn (SwarmResumed $event) => $event->runId === $runId);
});

test('durable waiting resume dispatches the join when all branches are terminal', function () {
    $response = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task');
    $runId = $response->runId;
    $manager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
    {
        /** @var array<int, int> */
        public array $stepDispatches = [];

        public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->stepDispatches[] = $stepIndex;

            return noopPendingDispatch();
        }
    };

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->update([
            'status' => 'completed',
            'output' => 'branch-output',
            'usage' => json_encode([]),
            'duration_ms' => 1,
            'finished_at' => now('UTC'),
            'execution_token' => null,
            'leased_until' => null,
        ]);

    $manager->pause($runId);
    $manager->resume($runId);

    expect($manager->find($runId)['status'])->toBe('pending')
        ->and($manager->find($runId)['next_step_index'])->toBe(3)
        ->and($manager->stepDispatches)->toContain(3);
});

test('durable pending resume dispatches through manager seam', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $manager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
    {
        /** @var array<int, int> */
        public array $stepDispatches = [];

        public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->stepDispatches[] = $stepIndex;

            return noopPendingDispatch();
        }
    };

    $manager->pause($response->runId);
    $manager->resume($response->runId);

    expect($manager->stepDispatches)->toBe([0]);
});

test('durable waiting cancel immediately cancels parent and non terminal branches', function () {
    Event::fake([SwarmCancelled::class]);

    $response = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->where('branch_id', 'parallel:0')
        ->update([
            'status' => 'completed',
            'output' => 'research-out',
            'usage' => json_encode([]),
            'duration_ms' => 1,
            'finished_at' => now('UTC'),
        ]);
    DB::table('swarm_durable_node_outputs')->insert([
        'run_id' => $runId,
        'node_id' => 'parallel:0',
        'output' => 'cancel-output',
        'expires_at' => now('UTC')->addHour(),
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    $manager->cancel($runId);

    $branches = app(DurableRunStore::class)->branchesFor($runId, 'parallel');

    expect($manager->find($runId)['status'])->toBe('cancelled')
        ->and(app(SwarmHistory::class)->find($runId)['status'])->toBe('cancelled')
        ->and($branches[0]['status'])->toBe('completed')
        ->and($branches[1]['status'])->toBe('cancelled')
        ->and($branches[2]['status'])->toBe('cancelled')
        ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $runId)->count())->toBe(0);
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

test('durable runs persist labels details and inspection records', function () {
    $context = RunContext::from('durable-task')
        ->withLabels(['tenant' => 'acme', 'priority' => 2, 'reviewed' => false])
        ->withDetails(['document' => ['id' => 'doc-1']]);

    $response = FakeSequentialSwarm::make()->dispatchDurable($context);
    $detail = app(DurableSwarmManager::class)->inspect($response->runId);

    expect($detail->labels)->toMatchArray([
        'tenant' => 'acme',
        'priority' => 2,
        'reviewed' => false,
    ])->and($detail->details)->toMatchArray([
        'document' => ['id' => 'doc-1'],
    ]);
});

test('durable waits accept idempotent signals and release the run', function () {
    Event::fake([
        SwarmWaiting::class,
        SwarmSignalled::class,
    ]);

    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $manager = app(DurableSwarmManager::class);

    $manager->wait($response->runId, 'approval_received', 'Waiting for approval', 3600);
    expect($manager->find($response->runId)['status'])->toBe('waiting');

    $first = $response->signal('approval_received', ['approved' => true], 'approval-1');
    $second = $response->signal('approval_received', ['approved' => true], 'approval-1');
    $detail = $manager->inspect($response->runId);

    expect($first->accepted)->toBeTrue()
        ->and($second->duplicate)->toBeTrue()
        ->and($manager->find($response->runId)['status'])->toBe('pending')
        ->and($detail->waits[0]['status'])->toBe('signalled')
        ->and($detail->signals)->toHaveCount(1);

    Event::assertDispatched(SwarmWaiting::class);
    Event::assertDispatched(SwarmSignalled::class);
});

test('durable wait timeout recovery uses concrete wait rows when a later wait has no timeout', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $manager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
    {
        /** @var array<int, int> */
        public array $stepDispatches = [];

        public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->stepDispatches[] = $stepIndex;

            return parent::dispatchStepJob($runId, $stepIndex, $connection, $queue);
        }
    };

    $manager->wait($response->runId, 'timed_wait', 'Timed wait', 60);
    $manager->wait($response->runId, 'manual_wait', 'Manual wait');

    $this->travel(61)->seconds();
    $manager->recover(runId: $response->runId);

    $waits = $manager->inspect($response->runId)->waits;

    expect($manager->find($response->runId)['status'])->toBe('waiting')
        ->and($manager->find($response->runId)['wait_timeout_at'])->toBeNull()
        ->and($manager->stepDispatches)->toBe([])
        ->and($waits[0]['status'])->toBe('timed_out')
        ->and($waits[1]['status'])->toBe('waiting');
});

test('durable wait timeout recovery keeps the next earliest open wait timeout', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $manager = app(DurableSwarmManager::class);

    $manager->wait($response->runId, 'first_wait', 'First wait', 60);
    $firstTimeout = $manager->find($response->runId)['wait_timeout_at'];
    $manager->wait($response->runId, 'second_wait', 'Second wait', 120);

    expect($manager->find($response->runId)['wait_timeout_at'])->toBe($firstTimeout);

    $this->travel(61)->seconds();
    $manager->recover(runId: $response->runId);

    $run = $manager->find($response->runId);
    $waits = $manager->inspect($response->runId)->waits;

    expect($run['status'])->toBe('waiting')
        ->and($run['wait_timeout_at'])->toBe($waits[1]['timeout_at'])
        ->and($waits[0]['status'])->toBe('timed_out')
        ->and($waits[1]['status'])->toBe('waiting');
});

test('durable wait release recomputes run wait summary from remaining open waits', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $manager = app(DurableSwarmManager::class);

    $manager->wait($response->runId, 'first_wait', 'First wait', 60);
    $manager->wait($response->runId, 'second_wait', 'Second wait', 120);
    $response->signal('first_wait', ['approved' => true], 'first-wait-signal');

    $run = $manager->find($response->runId);
    $waits = $manager->inspect($response->runId)->waits;

    expect($run['status'])->toBe('waiting')
        ->and($run['wait_reason'])->toBe('Second wait')
        ->and($run['wait_timeout_at'])->toBe($waits[1]['timeout_at'])
        ->and($waits[0]['status'])->toBe('signalled')
        ->and($waits[1]['status'])->toBe('waiting');
});

test('durable lifecycle events redact context metadata when capture is disabled', function () {
    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);

    $context = RunContext::fromTask('secret-input')
        ->mergeMetadata(['secret' => 'value', 'nested' => ['token' => 'abc']]);
    $response = FakeSequentialSwarm::make()->dispatchDurable($context);
    $events = new Dispatcher(app());
    $capturedWaiting = null;
    $capturedPaused = null;
    $events->listen(SwarmWaiting::class, function (SwarmWaiting $event) use (&$capturedWaiting): void {
        $capturedWaiting = $event;
    });
    $events->listen(SwarmPaused::class, function (SwarmPaused $event) use (&$capturedPaused): void {
        $capturedPaused = $event;
    });
    $signalHandler = new DurableSignalHandler(app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), $events, app(SwarmCapture::class), app(DurableRunContext::class), app(DurablePayloadCapture::class));
    $manager = new DurableSwarmManager(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), $events, app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), $signalHandler, app(DurableRetryHandler::class));

    $manager->wait($response->runId, 'manual_wait', 'Manual wait');
    $manager->pause($response->runId);

    expect($capturedWaiting)->toBeInstanceOf(SwarmWaiting::class)
        ->and($capturedWaiting->waitName)->toBe('manual_wait')
        ->and($capturedWaiting->metadata)->toMatchArray([
            'secret' => '[redacted]',
            'nested' => ['token' => '[redacted]'],
        ])
        ->and($capturedPaused)->toBeInstanceOf(SwarmPaused::class)
        ->and($capturedPaused->metadata)->toMatchArray([
            'secret' => '[redacted]',
            'nested' => ['token' => '[redacted]'],
        ]);
});

test('durable signal idempotency handles concurrent duplicate insert races', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');

    DB::unprepared(<<<'SQL'
CREATE TRIGGER swarm_signal_race BEFORE INSERT ON swarm_durable_signals
WHEN NEW.idempotency_key = 'approval-race'
BEGIN
    INSERT INTO swarm_durable_signals (
        run_id,
        name,
        status,
        payload,
        idempotency_key,
        consumed_step_index,
        consumed_at,
        created_at,
        updated_at
    ) VALUES (
        NEW.run_id,
        NEW.name,
        'recorded',
        NEW.payload,
        NEW.idempotency_key,
        NULL,
        NULL,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    );
END;
SQL);

    $signal = app(DurableRunStore::class)->recordSignal($response->runId, 'approval_received', ['approved' => true], 'approval-race');

    expect($signal['duplicate'])->toBeTrue()
        ->and(app(DurableRunStore::class)->signals($response->runId))->toHaveCount(1);
});

test('durable progress records latest parent and branch state', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $manager = app(DurableSwarmManager::class);

    $manager->recordProgress($response->runId, null, ['stage' => 'fetching']);
    $detail = $manager->inspect($response->runId);

    expect($detail->progress)->toHaveCount(1)
        ->and($detail->progress[0]['progress'])->toBe(['stage' => 'fetching'])
        ->and($manager->find($response->runId)['last_progress_at'])->not->toBeNull();
});

test('durable child swarms record lineage', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('parent-task');
    $child = app(DurableSwarmManager::class)->dispatchChildSwarm($response->runId, FakeSequentialSwarm::class, 'child-task');
    $detail = app(DurableSwarmManager::class)->inspect($response->runId);

    expect($child->parentRunId)->toBe($response->runId)
        ->and($child->childSwarmClass)->toBe(FakeSequentialSwarm::class)
        ->and($detail->children)->toHaveCount(1)
        ->and($detail->children[0]['child_run_id'])->toBe($child->childRunId);
});

test('durable child terminal reconciliation dispatches through manager seam', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('parent-task');
    $childContext = RunContext::fromTask('child-task');
    $waitName = 'child:'.$childContext->runId;
    app(DurableRunStore::class)->createWait($response->runId, $waitName, 'Waiting for child swarm.', null, []);
    app(DurableRunStore::class)->createChildRun($response->runId, $childContext->runId, FakeSequentialSwarm::class, $waitName, $childContext->toArray());
    app(DurableRunStore::class)->updateChildRun($childContext->runId, 'completed', 'child-output');

    $manager = new class(app('config'), app(DurableRunStore::class), app(DatabaseRunHistoryStore::class), app(ContextStore::class), app(ArtifactRepository::class), app('events'), app(SequentialRunner::class), app(HierarchicalRunner::class), app(DurableRunRecorder::class), app(SwarmStepRecorder::class), app('db')->connection(), app(SwarmCapture::class), app(SwarmPayloadLimits::class), app(), app(DurableRunInspector::class), app(DurableSignalHandler::class), app(DurableRetryHandler::class)) extends DurableSwarmManager
    {
        /** @var array<int, int> */
        public array $stepDispatches = [];

        public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->stepDispatches[] = $stepIndex;

            return noopPendingDispatch();
        }
    };

    $manager->recover(runId: $response->runId);

    expect($manager->stepDispatches)->toBe([0]);
});

test('durable retry schedules backoff and recovers after due time', function () {
    FlakyDurableAgent::reset(failuresBeforeSuccess: 1);

    $response = RetryableDurableSwarm::make()->dispatchDurable('retry-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    $run = $manager->find($response->runId);
    expect($run['status'])->toBe('pending')
        ->and($run['retry_attempt'])->toBe(1)
        ->and($run['next_retry_at'])->not->toBeNull()
        ->and(FlakyDurableAgent::$attempts)->toBe(1);

    Artisan::call('swarm:recover');
    expect(FlakyDurableAgent::$attempts)->toBe(1);

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect($manager->find($response->runId)['status'])->toBe('completed')
        ->and(FlakyDurableAgent::$attempts)->toBe(2);
});

test('durable retry respects non retryable exceptions', function () {
    FlakyDurableAgent::reset(failuresBeforeSuccess: 1, exceptionClass: InvalidArgumentException::class);

    $response = RetryableDurableSwarm::make()->dispatchDurable('retry-task');
    $manager = app(DurableSwarmManager::class);

    expect(fn () => (new AdvanceDurableSwarm($response->runId, 0))->handle($manager))
        ->toThrow(InvalidArgumentException::class);

    expect($manager->find($response->runId)['status'])->toBe('failed')
        ->and($manager->find($response->runId)['retry_attempt'])->toBe(0);
});

test('durable branch retries independently before parent join', function () {
    FlakyDurableAgent::reset(failuresBeforeSuccess: 1);
    FakeResearcher::fake(['stable-branch']);

    $response = RetryableParallelDurableSwarm::make()->dispatchDurable('parallel-retry-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    (new AdvanceDurableBranch($response->runId, 'parallel:0'))->handle($manager);
    (new AdvanceDurableBranch($response->runId, 'parallel:1'))->handle($manager);

    $branches = app(DurableRunStore::class)->branchesFor($response->runId, 'parallel');
    expect($branches[0]['status'])->toBe('completed')
        ->and($branches[1]['status'])->toBe('pending')
        ->and($branches[1]['retry_attempt'])->toBe(1)
        ->and($manager->find($response->runId)['status'])->toBe('waiting');

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');
    (new AdvanceDurableBranch($response->runId, 'parallel:1'))->handle($manager);

    expect($manager->find($response->runId)['status'])->toBe('pending');

    (new AdvanceDurableSwarm($response->runId, 2))->handle($manager);

    expect($manager->find($response->runId)['status'])->toBe('completed')
        ->and(app(SwarmHistory::class)->find($response->runId)['output'])->toBe("stable-branch\n\nflaky-success");
});

test('declarative durable waits checkpoint and resume from signal', function () {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);

    $response = DurableWaitAttributeSwarm::make()->dispatchDurable('wait-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect($manager->find($response->runId)['status'])->toBe('waiting')
        ->and($manager->inspect($response->runId)->waits[0]['name'])->toBe('approval_received');

    $response->signal('approval_received', ['approved' => true], 'approval-attribute');
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);

    expect($manager->find($response->runId)['status'])->toBe('completed')
        ->and(app(SwarmHistory::class)->find($response->runId)['output'])->toBe('writer-out');
});

test('durable child swarms wait and record terminal status on the parent', function () {
    FakeResearcher::fake(['parent-step', 'child-research']);
    FakeWriter::fake(['parent-final', 'child-writer']);
    FakeEditor::fake(['child-output']);

    $response = ChildDispatchingSwarm::make()->dispatchDurable('parent-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    $detail = $manager->inspect($response->runId);
    $childRunId = $detail->children[0]['child_run_id'];

    expect($manager->find($response->runId)['status'])->toBe('waiting');

    (new AdvanceDurableSwarm($childRunId, 0))->handle($manager);
    (new AdvanceDurableSwarm($childRunId, 1))->handle($manager);
    (new AdvanceDurableSwarm($childRunId, 2))->handle($manager);

    expect($manager->find($response->runId)['status'])->toBe('pending');

    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);

    $parentContext = app(ContextStore::class)->find($response->runId);

    expect($manager->find($response->runId)['status'])->toBe('completed')
        ->and($parentContext['metadata']['durable_child_runs'][$childRunId]['status'])->toBe('completed')
        ->and($parentContext['metadata']['durable_child_runs'][$childRunId])->not->toHaveKey('output')
        ->and($parentContext['metadata']['durable_child_runs'][$childRunId])->not->toHaveKey('failure')
        ->and($manager->inspect($response->runId)->children[0]['output'])->toBe('child-output');
});

test('parallel durable child swarm completion releases the parent wait', function () {
    FakeResearcher::fake(['parent-step', 'child-research']);
    FakeWriter::fake(['child-writer', 'parent-final']);
    FakeEditor::fake(['child-editor']);

    $response = ParallelChildDispatchingSwarm::make()->dispatchDurable('parent-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    $childRunId = $manager->inspect($response->runId)->children[0]['child_run_id'];

    (new AdvanceDurableSwarm($childRunId, 0))->handle($manager);

    foreach (app(DurableRunStore::class)->branchesFor($childRunId, 'parallel') as $branch) {
        (new AdvanceDurableBranch($childRunId, $branch['branch_id']))->handle($manager);
    }

    (new AdvanceDurableSwarm($childRunId, 3))->handle($manager);

    $child = app(DurableRunStore::class)->childRunForChild($childRunId);
    $parentContext = app(ContextStore::class)->find($response->runId);

    expect($manager->find($response->runId)['status'])->toBe('pending')
        ->and($child['status'])->toBe('completed')
        ->and($child['output'])->toBe("child-research\n\nchild-writer\n\nchild-editor")
        ->and($parentContext['metadata']['durable_child_runs'][$childRunId]['status'])->toBe('completed')
        ->and($manager->inspect($response->runId)->waits[0]['status'])->toBe('child_completed');
});

test('parallel durable child swarm failure releases the parent wait as failed', function () {
    FakeResearcher::fake(['parent-step', 'child-research']);
    FakeWriter::fake(['parent-final', 'child-writer']);

    $response = FailingParallelChildDispatchingSwarm::make()->dispatchDurable('parent-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    $childRunId = $manager->inspect($response->runId)->children[0]['child_run_id'];

    (new AdvanceDurableSwarm($childRunId, 0))->handle($manager);

    foreach (app(DurableRunStore::class)->branchesFor($childRunId, 'parallel') as $branch) {
        (new AdvanceDurableBranch($childRunId, $branch['branch_id']))->handle($manager);
    }

    (new AdvanceDurableSwarm($childRunId, 3))->handle($manager);

    $child = app(DurableRunStore::class)->childRunForChild($childRunId);
    $parentContext = app(ContextStore::class)->find($response->runId);

    expect($manager->find($response->runId)['status'])->toBe('pending')
        ->and($child['status'])->toBe('failed')
        ->and($child['failure']['class'])->toBe(SwarmException::class)
        ->and($parentContext['metadata']['durable_child_runs'][$childRunId]['status'])->toBe('failed')
        ->and($manager->inspect($response->runId)->waits[0]['status'])->toBe('child_failed');
});

test('durable child lineage context payload is redacted when input capture is disabled', function () {
    config()->set('swarm.capture.inputs', false);
    FakeResearcher::fake(['parent-step']);

    $response = ChildDispatchingSwarm::make()->dispatchDurable('sensitive-parent-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    $child = $manager->inspect($response->runId)->children[0];

    expect($child['status'])->toBe('pending')
        ->and($child['dispatched_at'])->not->toBeNull()
        ->and($child['context_payload']['input'])->toBe('[redacted]')
        ->and($child['context_payload']['data'])->toBe(['input' => '[redacted]']);
});

test('durable child intent recovers when crash happens before child dispatch', function () {
    FakeResearcher::fake(['parent-step']);

    $response = ChildDispatchingSwarm::make()->dispatchDurable('parent-task');
    $manager = app(DurableSwarmManager::class);
    $manager->afterChildIntentForTesting(function (): void {
        throw new RuntimeException('crash after child intent');
    });

    expect(fn () => (new AdvanceDurableSwarm($response->runId, 0))->handle($manager))
        ->toThrow(RuntimeException::class, 'crash after child intent');

    $child = app(DurableRunStore::class)->childRuns($response->runId)[0];

    expect($manager->find($response->runId)['status'])->toBe('waiting')
        ->and($child['dispatched_at'])->toBeNull()
        ->and($manager->find($child['child_run_id']))->toBeNull();

    $manager->afterChildIntentForTesting(null);
    $manager->recover(runId: $response->runId);

    $child = app(DurableRunStore::class)->childRunForChild($child['child_run_id']);

    expect($child['dispatched_at'])->not->toBeNull()
        ->and($manager->find($child['child_run_id']))->not->toBeNull();
});

test('durable child start failures release the parent wait with failed child status', function () {
    FakeResearcher::fake(['parent-step']);

    $response = ChildDispatchingSwarm::make()->dispatchDurable('parent-task');
    $manager = app(DurableSwarmManager::class);

    config()->set('swarm.limits.max_input_bytes', 1);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    $detail = $manager->inspect($response->runId);
    $child = $detail->children[0];
    $parentContext = app(ContextStore::class)->find($response->runId);

    expect($manager->find($response->runId)['status'])->toBe('pending')
        ->and($child['status'])->toBe('failed')
        ->and($child['failure']['class'])->toBe(SwarmException::class)
        ->and($detail->waits[0]['status'])->toBe('child_failed')
        ->and($parentContext['metadata']['durable_child_runs'][$child['child_run_id']]['status'])->toBe('failed')
        ->and($parentContext['metadata']['durable_child_runs'][$child['child_run_id']])->not->toHaveKey('output')
        ->and($parentContext['metadata']['durable_child_runs'][$child['child_run_id']])->not->toHaveKey('failure');
});

test('durable child start failures redact lineage failure messages when capture is disabled', function () {
    config()->set('swarm.capture.outputs', false);
    FakeResearcher::fake(['parent-step']);

    $response = ChildDispatchingSwarm::make()->dispatchDurable('parent-task');
    $manager = app(DurableSwarmManager::class);

    config()->set('swarm.limits.max_input_bytes', 1);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    $child = $manager->inspect($response->runId)->children[0];

    expect($child['status'])->toBe('failed')
        ->and($child['failure']['class'])->toBe(SwarmException::class)
        ->and($child['failure']['message'])->toBe('[redacted]');
});

test('durable child recovery does not create a second child run when dispatch marker is missing', function () {
    FakeResearcher::fake(['parent-step']);

    $response = ChildDispatchingSwarm::make()->dispatchDurable('parent-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    $child = app(DurableRunStore::class)->childRuns($response->runId)[0];

    DB::table('swarm_durable_child_runs')
        ->where('child_run_id', $child['child_run_id'])
        ->update(['dispatched_at' => null]);

    $manager->recover(runId: $response->runId);

    expect(DB::table('swarm_durable_runs')->where('run_id', $child['child_run_id'])->count())->toBe(1)
        ->and(app(DurableRunStore::class)->childRunForChild($child['child_run_id'])['dispatched_at'])->not->toBeNull();
});

test('durable child terminal reconciliation emits one terminal event', function () {
    Event::fake([SwarmChildCompleted::class]);
    FakeResearcher::fake(['parent-step', 'child-research']);
    FakeWriter::fake(['parent-final', 'child-writer']);
    FakeEditor::fake(['child-output']);

    $response = ChildDispatchingSwarm::make()->dispatchDurable('parent-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    $childRunId = $manager->inspect($response->runId)->children[0]['child_run_id'];

    (new AdvanceDurableSwarm($childRunId, 0))->handle($manager);
    (new AdvanceDurableSwarm($childRunId, 1))->handle($manager);
    (new AdvanceDurableSwarm($childRunId, 2))->handle($manager);

    $manager->recover(runId: $response->runId);
    $manager->recover(runId: $response->runId);

    Event::assertDispatchedTimes(SwarmChildCompleted::class, 1);
});

test('durable parent cancellation cancels undispatched child intents', function () {
    FakeResearcher::fake(['parent-step']);

    $response = ChildDispatchingSwarm::make()->dispatchDurable('parent-task');
    $manager = app(DurableSwarmManager::class);
    $manager->afterChildIntentForTesting(function (): void {
        throw new RuntimeException('crash after child intent');
    });

    expect(fn () => (new AdvanceDurableSwarm($response->runId, 0))->handle($manager))
        ->toThrow(RuntimeException::class);

    $child = app(DurableRunStore::class)->childRuns($response->runId)[0];

    $manager->cancel($response->runId);

    expect(app(DurableRunStore::class)->childRunForChild($child['child_run_id'])['status'])->toBe('cancelled');
});

test('durable payload surfaces honor capture redaction', function () {
    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);

    $response = FakeSequentialSwarm::make()->dispatchDurable('sensitive-task');
    $manager = app(DurableSwarmManager::class);

    $manager->updateDetails($response->runId, ['secret' => 'value']);
    $manager->recordProgress($response->runId, null, ['secret' => 'value']);
    $manager->wait($response->runId, 'approval_received', metadata: ['secret' => 'value']);
    $response->signal('approval_received', ['secret' => 'value'], 'redacted-signal');

    $detail = $manager->inspect($response->runId);

    expect($detail->details['secret'])->toBe('[redacted]')
        ->and($detail->progress[0]['progress']['secret'])->toBe('[redacted]')
        ->and($detail->waits[0]['metadata']['secret'])->toBe('[redacted]')
        ->and($detail->signals[0]['payload']['secret'])->toBe('[redacted]');
});

test('durable operation commands inspect signal and progress runs', function () {
    $response = FakeSequentialSwarm::make()->dispatchDurable('command-task');
    $manager = app(DurableSwarmManager::class);

    $manager->wait($response->runId, 'approval_received');
    $manager->recordProgress($response->runId, null, ['stage' => 'waiting']);

    Artisan::call('swarm:progress', ['runId' => $response->runId]);
    expect(Artisan::output())->toContain('waiting');

    Artisan::call('swarm:signal', [
        'runId' => $response->runId,
        'name' => 'approval_received',
        '--payload' => '{"approved":true}',
        '--idempotency-key' => 'command-signal',
    ]);
    expect(Artisan::output())->toContain('accepted');

    Artisan::call('swarm:inspect', ['runId' => $response->runId, '--json' => true]);
    expect(Artisan::output())->toContain('approval_received');
});

test('webhook start requests are idempotent and auth modes fail closed', function () {
    config()->set('swarm.durable.webhooks.enabled', true);
    config()->set('swarm.durable.webhooks.auth.driver', 'signed');
    config()->set('swarm.durable.webhooks.auth.secret', 'secret');

    SwarmWebhooks::routes([FakeSequentialSwarm::class]);

    $payload = json_encode(['input' => 'webhook-task'], JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'secret');
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SWARM_TIMESTAMP' => $timestamp,
        'HTTP_X_SWARM_SIGNATURE' => $signature,
        'HTTP_IDEMPOTENCY_KEY' => 'start-1',
    ];

    $first = $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], $headers, $payload);
    $second = $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], $headers, $payload);

    $first->assertAccepted();
    $second->assertOk()
        ->assertJsonPath('run_id', $first->json('run_id'))
        ->assertJsonPath('duplicate', true);

    config()->set('swarm.durable.webhooks.auth.secret', null);

    expect(fn () => SwarmWebhooks::routes([FakeSequentialSwarm::class]))
        ->toThrow(SwarmException::class);
});

test('webhook start idempotency rejects in flight and mismatched duplicate requests', function () {
    config()->set('swarm.durable.webhooks.enabled', true);
    config()->set('swarm.durable.webhooks.auth.driver', 'signed');
    config()->set('swarm.durable.webhooks.auth.secret', 'secret');

    SwarmWebhooks::routes([FakeSequentialSwarm::class]);

    $payload = json_encode(['input' => 'webhook-task'], JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'secret');
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SWARM_TIMESTAMP' => $timestamp,
        'HTTP_X_SWARM_SIGNATURE' => $signature,
        'HTTP_IDEMPOTENCY_KEY' => 'reserved-start',
    ];

    app(DurableRunStore::class)->reserveWebhookIdempotency(
        'start:'.FakeSequentialSwarm::class,
        'reserved-start',
        hash('sha256', $payload),
    );

    $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], $headers, $payload)
        ->assertConflict()
        ->assertJsonPath('message', 'Idempotency key is already processing.');

    $differentPayload = json_encode(['input' => 'different-task'], JSON_THROW_ON_ERROR);
    $differentTimestamp = (string) time();
    $differentHeaders = array_merge($headers, [
        'HTTP_X_SWARM_TIMESTAMP' => $differentTimestamp,
        'HTTP_X_SWARM_SIGNATURE' => hash_hmac('sha256', $differentTimestamp.'.'.$differentPayload, 'secret'),
    ]);

    $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], $differentHeaders, $differentPayload)
        ->assertConflict()
        ->assertJsonPath('message', 'Idempotency key was already used with a different request payload.');
});

test('webhook start idempotency reclaims failed reservations for matching retries only', function () {
    config()->set('swarm.durable.webhooks.enabled', true);
    config()->set('swarm.durable.webhooks.auth.driver', 'signed');
    config()->set('swarm.durable.webhooks.auth.secret', 'secret');

    SwarmWebhooks::routes([FakeSequentialSwarm::class]);

    $payload = json_encode(['input' => 'webhook-task'], JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SWARM_TIMESTAMP' => $timestamp,
        'HTTP_X_SWARM_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$payload, 'secret'),
        'HTTP_IDEMPOTENCY_KEY' => 'failed-start',
    ];

    config()->set('swarm.limits.max_input_bytes', 1);
    $this->withoutExceptionHandling();

    expect(fn () => $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], $headers, $payload))
        ->toThrow(SwarmException::class);

    $failedReservation = DB::table('swarm_durable_webhook_idempotency')
        ->where('idempotency_key', 'failed-start')
        ->first();

    expect($failedReservation->status)->toBe('failed')
        ->and($failedReservation->run_id)->toBeNull();

    $this->withExceptionHandling();

    $differentPayload = json_encode(['input' => 'different-task'], JSON_THROW_ON_ERROR);
    $differentTimestamp = (string) time();

    $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], array_merge($headers, [
        'HTTP_X_SWARM_TIMESTAMP' => $differentTimestamp,
        'HTTP_X_SWARM_SIGNATURE' => hash_hmac('sha256', $differentTimestamp.'.'.$differentPayload, 'secret'),
    ]), $differentPayload)
        ->assertConflict()
        ->assertJsonPath('message', 'Idempotency key was already used with a different request payload.');

    config()->set('swarm.limits.max_input_bytes', null);
    $this->travel(2)->seconds();

    $retryTimestamp = (string) time();
    $retry = $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], array_merge($headers, [
        'HTTP_X_SWARM_TIMESTAMP' => $retryTimestamp,
        'HTTP_X_SWARM_SIGNATURE' => hash_hmac('sha256', $retryTimestamp.'.'.$payload, 'secret'),
    ]), $payload);

    $retry->assertAccepted()->assertJsonStructure(['run_id']);

    $completedReservation = DB::table('swarm_durable_webhook_idempotency')
        ->where('idempotency_key', 'failed-start')
        ->first();

    expect($completedReservation->status)->toBe('completed')
        ->and($completedReservation->run_id)->toBe($retry->json('run_id'))
        ->and($completedReservation->updated_at)->not->toBe($failedReservation->updated_at);
});

test('swarm prune removes stale no run webhook idempotency reservations', function () {
    config()->set('swarm.context.ttl', 86400);
    config()->set('swarm.durable.webhooks.idempotency_ttl', 60);

    app(DurableRunStore::class)->reserveWebhookIdempotency('start:'.FakeSequentialSwarm::class, 'stale-failed', 'hash-a');
    app(DurableRunStore::class)->failWebhookIdempotency('start:'.FakeSequentialSwarm::class, 'stale-failed');
    app(DurableRunStore::class)->reserveWebhookIdempotency('start:'.FakeSequentialSwarm::class, 'stale-reserved', 'hash-b');
    app(DurableRunStore::class)->reserveWebhookIdempotency('start:'.FakeSequentialSwarm::class, 'fresh-reserved', 'hash-c');

    DB::table('swarm_durable_webhook_idempotency')
        ->whereIn('idempotency_key', ['stale-failed', 'stale-reserved'])
        ->update(['updated_at' => now('UTC')->subMinutes(10)]);

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_durable_webhook_idempotency')->where('idempotency_key', 'stale-failed')->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_webhook_idempotency')->where('idempotency_key', 'stale-reserved')->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_webhook_idempotency')->where('idempotency_key', 'fresh-reserved')->exists())->toBeTrue();
});

test('swarm webhooks require signed requests and dispatch durable starts', function () {
    config()->set('swarm.durable.webhooks.enabled', true);
    config()->set('swarm.durable.webhooks.auth.driver', 'signed');
    config()->set('swarm.durable.webhooks.auth.secret', 'secret');

    SwarmWebhooks::routes([FakeSequentialSwarm::class]);

    $payload = json_encode(['input' => 'webhook-task'], JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'secret');

    $this->postJson('/swarm/webhooks/start/fake-sequential-swarm', ['input' => 'webhook-task'])
        ->assertUnauthorized();

    $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SWARM_TIMESTAMP' => $timestamp,
        'HTTP_X_SWARM_SIGNATURE' => $signature,
    ], $payload)->assertAccepted()->assertJsonStructure(['run_id']);
});

test('swarm webhooks token auth rejects blank config and invalid bearer tokens', function () {
    config()->set('swarm.durable.webhooks.enabled', true);
    config()->set('swarm.durable.webhooks.auth.driver', 'token');
    config()->set('swarm.durable.webhooks.auth.token', 'webhook-token');

    SwarmWebhooks::routes([FakeSequentialSwarm::class]);

    $payload = json_encode(['input' => 'webhook-task'], JSON_THROW_ON_ERROR);
    $baseHeaders = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], array_merge($baseHeaders, [
        'HTTP_AUTHORIZATION' => 'Bearer wrong',
    ]), $payload)->assertUnauthorized();

    $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], $baseHeaders, $payload)
        ->assertUnauthorized();

    $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], array_merge($baseHeaders, [
        'HTTP_AUTHORIZATION' => 'Bearer ',
    ]), $payload)->assertUnauthorized();

    $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], array_merge($baseHeaders, [
        'HTTP_AUTHORIZATION' => 'Bearer webhook-token',
    ]), $payload)->assertAccepted()->assertJsonStructure(['run_id']);

    config()->set('swarm.durable.webhooks.auth.token', null);

    $this->withoutExceptionHandling();

    expect(fn () => $this->call('POST', '/swarm/webhooks/start/fake-sequential-swarm', [], [], [], array_merge($baseHeaders, [
        'HTTP_AUTHORIZATION' => 'Bearer webhook-token',
    ]), $payload))->toThrow(SwarmException::class, 'Token swarm webhooks require [SWARM_WEBHOOK_TOKEN].');

    $this->withExceptionHandling();
});
