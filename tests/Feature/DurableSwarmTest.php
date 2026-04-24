<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Database\Schema\Blueprint;
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
