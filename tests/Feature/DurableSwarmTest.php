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
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
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
    app()->forgetInstance(DurableSwarmManager::class);
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

test('dispatch durable fails for non sequential swarms', function () {
    expect(fn () => FakeParallelSwarm::make()->dispatchDurable('queued-task'))
        ->toThrow(SwarmException::class, 'Durable execution is only supported for sequential swarms in this release.');
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

    expect($history['error'])->toMatchArray([
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

    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update([
            'status' => 'pending',
            'execution_token' => null,
            'leased_until' => null,
            'updated_at' => now()->subMinutes(10),
        ]);

    Artisan::call('swarm:recover');

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['status'])->toBe('completed')
        ->and($history['steps'])->toHaveCount(3)
        ->and($history['output'])->toBe('editor-out');
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

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('paused');
    expect(app(SwarmHistory::class)->find($runId)['status'])->toBe('paused');
    Event::assertDispatched(SwarmPaused::class, fn (SwarmPaused $event) => $event->runId === $runId);

    Artisan::call('swarm:resume', ['runId' => $runId]);

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('pending');
    expect(app(SwarmHistory::class)->find($runId)['status'])->toBe('pending');
    Event::assertDispatched(SwarmResumed::class, fn (SwarmResumed $event) => $event->runId === $runId);

    Artisan::call('swarm:cancel', ['runId' => $runId]);

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('cancelled');
    expect(app(SwarmHistory::class)->find($runId)['status'])->toBe('cancelled');
    Event::assertDispatched(SwarmCancelled::class, fn (SwarmCancelled $event) => $event->runId === $runId);
});

test('durable recovery can resume after checkpoint persistence before next dispatch', function () {
    $manager = app(DurableSwarmManager::class);
    $response = FakeSequentialSwarm::make()->dispatchDurable('durable-task');
    $runId = $response->runId;

    $manager->afterStepCheckpointUsing(function (): void {
        throw new RuntimeException('Simulated crash after checkpoint.');
    });

    expect(fn () => (new AdvanceDurableSwarm($runId, 0))->handle($manager))
        ->toThrow(RuntimeException::class, 'Simulated crash after checkpoint.');

    $manager->afterStepCheckpointUsing(null);

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
