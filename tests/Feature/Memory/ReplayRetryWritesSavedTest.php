<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmStepRecorder;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryRecallAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemorySpyFlakyAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryWritingFlakyStreamAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksStepWhenIndex;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ReplayWriteHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ReplayWriteSequentialStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ReplayWriteSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ReplayWriteStaticHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

function configureReplayRetryWritesRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([
        ContextStore::class,
        ArtifactRepository::class,
        RunHistoryStore::class,
        DurableRunStore::class,
        SwarmMemory::class,
        SequentialRunner::class,
        SwarmRunner::class,
        DurableSwarmManager::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

function recoverReplayRetry(): void
{
    test()->travel(61)->seconds();
    Artisan::call('swarm:recover');
}

function expectRetryWriteSavedAndRead(string $runId): void
{
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write'))->toBe('retry-value')
        ->and(MemoryRecallAgent::$seen)->toContain('retry-write: retry-value');
}

beforeEach(function () {
    configureReplayRetryWritesRuntime();
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    MemorySpyFlakyAgent::reset();
    MemoryWritingFlakyStreamAgent::reset();
    MemoryRecallAgent::reset();
    FakeResearcher::fake(['stable-branch']);
});

test('durable sequential blocking retry saves its write before the downstream step', function () {
    $response = ReplayWriteSequentialSwarm::make()->dispatchDurable('retry-write');
    $manager = app(DurableSwarmManager::class);
    MemorySpyFlakyAgent::reset($response->runId);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    recoverReplayRetry();
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $response->runId, 'retry-write'))->toBe('retry-value');
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);
    expectRetryWriteSavedAndRead($response->runId);
});

test('durable sequential streaming retry saves its write before the downstream step', function () {
    $response = ReplayWriteSequentialStreamingSwarm::make()->dispatchDurable('retry-write');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    recoverReplayRetry();
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $response->runId, 'retry-write'))->toBe('retry-value');
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);
    expectRetryWriteSavedAndRead($response->runId);
});

test('durable hierarchical dynamic retry saves its write before the downstream worker', function () {
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('writer', [
            'writer' => ['type' => 'worker', 'agent' => MemorySpyFlakyAgent::class, 'prompt' => 'write-on-retry', 'next' => 'reader'],
            'reader' => ['type' => 'worker', 'agent' => MemoryRecallAgent::class, 'prompt' => 'read-retry-write', 'next' => 'finish'],
            'finish' => ['type' => 'finish', 'output_from' => 'reader'],
        ]),
    ]);
    $response = ReplayWriteHierarchicalSwarm::make()->dispatchDurable('retry-write');
    $manager = app(DurableSwarmManager::class);
    MemorySpyFlakyAgent::reset($response->runId);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);
    recoverReplayRetry();
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $response->runId, 'retry-write'))->toBe('retry-value');
    (new AdvanceDurableSwarm($response->runId, 2))->handle($manager);
    expectRetryWriteSavedAndRead($response->runId);
});

test('durable hierarchical static retry saves its write before the downstream worker', function () {
    $response = ReplayWriteStaticHierarchicalSwarm::make()->dispatchDurable('retry-write');
    $manager = app(DurableSwarmManager::class);
    MemorySpyFlakyAgent::reset($response->runId);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);
    recoverReplayRetry();
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $response->runId, 'retry-write'))->toBe('retry-value');
    (new AdvanceDurableSwarm($response->runId, 2))->handle($manager);
    expectRetryWriteSavedAndRead($response->runId);
});

test('durable branch retry saves its write before the downstream worker', function () {
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('parallel', [
            'parallel' => ['type' => 'parallel', 'branches' => ['writer', 'stable'], 'next' => 'reader'],
            'writer' => ['type' => 'worker', 'agent' => MemorySpyFlakyAgent::class, 'prompt' => 'write-on-retry'],
            'stable' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'stable'],
            'reader' => ['type' => 'worker', 'agent' => MemoryRecallAgent::class, 'prompt' => 'read-retry-write', 'next' => 'finish'],
            'finish' => ['type' => 'finish', 'output_from' => 'reader'],
        ]),
    ]);
    $response = ReplayWriteHierarchicalSwarm::make()->dispatchDurable('retry-write');
    $manager = app(DurableSwarmManager::class);
    MemorySpyFlakyAgent::reset($response->runId);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);
    $branches = app(DurableRunStore::class)->branchesFor($response->runId, 'parallel');
    $writer = collect($branches)->firstWhere('agent_class', MemorySpyFlakyAgent::class);
    $stable = collect($branches)->firstWhere('agent_class', FakeResearcher::class);
    (new AdvanceDurableBranch($response->runId, $stable['branch_id']))->handle($manager);
    (new AdvanceDurableBranch($response->runId, $writer['branch_id']))->handle($manager);
    recoverReplayRetry();
    (new AdvanceDurableBranch($response->runId, $writer['branch_id']))->handle($manager);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $response->runId, 'retry-write'))->toBe('retry-value');
    $joinStep = $manager->find($response->runId)['next_step_index'];
    (new AdvanceDurableSwarm($response->runId, $joinStep))->handle($manager);
    $readerStep = $manager->find($response->runId)['next_step_index'];
    (new AdvanceDurableSwarm($response->runId, $readerStep))->handle($manager);
    expectRetryWriteSavedAndRead($response->runId);
});

test('a durable branch retry that writes and then throws does not commit on its false return', function () {
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('parallel', [
            'parallel' => ['type' => 'parallel', 'branches' => ['writer', 'stable'], 'next' => 'finish'],
            'writer' => ['type' => 'worker', 'agent' => MemorySpyFlakyAgent::class, 'prompt' => 'write-on-retry'],
            'stable' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'stable'],
            'finish' => ['type' => 'finish', 'output_from' => 'stable'],
        ]),
    ]);
    $response = ReplayWriteHierarchicalSwarm::make()->dispatchDurable('retry-write');
    $manager = app(DurableSwarmManager::class);
    MemorySpyFlakyAgent::reset($response->runId);
    MemorySpyFlakyAgent::$failAfterWrite = true;

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);
    $branches = app(DurableRunStore::class)->branchesFor($response->runId, 'parallel');
    $writer = collect($branches)->firstWhere('agent_class', MemorySpyFlakyAgent::class);
    (new AdvanceDurableBranch($response->runId, $writer['branch_id']))->handle($manager);
    recoverReplayRetry();
    (new AdvanceDurableBranch($response->runId, $writer['branch_id']))->handle($manager);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $response->runId, 'retry-write'))->toBeNull();
});

test('a retry that writes and then throws leaves live memory unchanged', function () {
    $response = ReplayWriteSequentialSwarm::make()->dispatchDurable('retry-write');
    $manager = app(DurableSwarmManager::class);
    MemorySpyFlakyAgent::reset($response->runId);
    MemorySpyFlakyAgent::$failAfterWrite = true;

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    recoverReplayRetry();
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $response->runId, 'retry-write'))->toBeNull();
});

test('a guardrail failure after a retry write leaves live memory unchanged', function () {
    config()->set('swarm.guardrails.step', [new BlocksStepWhenIndex(0)]);
    $response = ReplayWriteSequentialSwarm::make()->dispatchDurable('retry-write');
    $manager = app(DurableSwarmManager::class);
    MemorySpyFlakyAgent::reset($response->runId);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    recoverReplayRetry();
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $response->runId, 'retry-write'))->toBeNull();
});

test('a step-recording failure after a retry write leaves live memory unchanged', function () {
    $recorder = Mockery::mock(app(SwarmStepRecorder::class))->makePartial();
    $recorder->shouldReceive('completed')->once()->andThrow(new RuntimeException('step-recording-failed'));
    app()->instance(SwarmStepRecorder::class, $recorder);
    app()->forgetInstance(SequentialRunner::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(DurableSwarmManager::class);

    $response = ReplayWriteSequentialSwarm::make()->dispatchDurable('retry-write');
    $manager = app(DurableSwarmManager::class);
    MemorySpyFlakyAgent::reset($response->runId);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    recoverReplayRetry();
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $response->runId, 'retry-write'))->toBeNull();
});

test('a durable checkpoint failure after commit leaves the retry write saved', function () {
    $response = ReplayWriteSequentialSwarm::make()->dispatchDurable('retry-write');
    $manager = app(DurableSwarmManager::class);
    MemorySpyFlakyAgent::reset($response->runId);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    recoverReplayRetry();
    $manager->beforeStepCheckpointForTesting(fn () => throw new RuntimeException('checkpoint-failed-after-commit'));

    expect(fn () => (new AdvanceDurableSwarm($response->runId, 0))->handle($manager))
        ->toThrow(RuntimeException::class, 'checkpoint-failed-after-commit');

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $response->runId, 'retry-write'))->toBe('retry-value');
});
