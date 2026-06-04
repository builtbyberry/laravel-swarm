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
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemorySpyFlakyAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FreshExecutionReplaySwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ReplayDeterminismHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ReplayDeterminismParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ReplayDeterminismSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use Illuminate\Support\Facades\Artisan;

/**
 * Proves that the replay coordinator serves a frozen memory snapshot to a
 * retried agent rather than the live (mutated) memory at retry time.
 *
 * Each test follows the same pattern:
 *
 *   1. Dispatch the durable swarm.
 *   2. Write 'initial-value' to the probe key in Run-scoped memory.
 *   3. Advance the step → MemorySpyFlakyAgent crashes on its first attempt,
 *      but records what it read from SwarmMemory.
 *   4. Overwrite the probe key with 'mutated-value'.
 *   5. Travel 61 s past the retry backoff and call swarm:recover.
 *   6. Advance the step again → MemorySpyFlakyAgent succeeds on its second
 *      attempt and records what it read.
 *   7. Assert that on the second attempt the agent still saw 'initial-value'
 *      (the frozen snapshot), not 'mutated-value'.
 *
 * A final test verifies the FreshExecution opt-out: with replay mode overridden
 * to FreshExecution the agent sees the live 'mutated-value' on retry.
 */
pest()->group('compliance');

beforeEach(function () {
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

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    MemorySpyFlakyAgent::reset();
});

test('sequential replay coordinator serves frozen snapshot to retried agent', function () {
    $response = ReplayDeterminismSequentialSwarm::make()->dispatchDurable('replay-determinism-task');
    $manager = app(DurableSwarmManager::class);

    // Write the initial probe value and wire the spy to this run.
    app(SwarmMemory::class)->put(MemoryScope::Run, $response->runId, 'probe-key', 'initial-value');
    MemorySpyFlakyAgent::reset($response->runId);

    // First advance: agent crashes and records what memory it read.
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect(MemorySpyFlakyAgent::$seenValues[1])->toBe('initial-value')
        ->and($manager->find($response->runId)['status'])->toBe('pending');

    // Mutate memory after the crash — the retry must NOT see this value.
    app(SwarmMemory::class)->put(MemoryScope::Run, $response->runId, 'probe-key', 'mutated-value');

    // Let the backoff expire and recover.
    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');

    // Second advance: agent succeeds and records what the replay coordinator gave it.
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect(MemorySpyFlakyAgent::$attempts)->toBe(2)
        ->and(MemorySpyFlakyAgent::$seenValues[2])->toBe('initial-value')
        ->and($manager->find($response->runId)['status'])->toBe('completed');
});

test('parallel replay coordinator serves frozen snapshot to retried branch agent', function () {
    FakeResearcher::fake(['stable-branch-output']);

    $response = ReplayDeterminismParallelSwarm::make()->dispatchDurable('replay-determinism-parallel-task');
    $manager = app(DurableSwarmManager::class);

    // Write the initial probe value and wire the spy to this run.
    app(SwarmMemory::class)->put(MemoryScope::Run, $response->runId, 'probe-key', 'initial-value');
    MemorySpyFlakyAgent::reset($response->runId);

    // Dispatch parallel branches (step 0 of a parallel swarm fans out).
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    // Branch 0 (FakeResearcher) completes cleanly.
    (new AdvanceDurableBranch($response->runId, 'parallel:0'))->handle($manager);

    // Branch 1 (MemorySpyFlakyAgent) crashes on its first attempt.
    (new AdvanceDurableBranch($response->runId, 'parallel:1'))->handle($manager);

    expect(MemorySpyFlakyAgent::$seenValues[1])->toBe('initial-value');

    // Mutate memory after the crash.
    app(SwarmMemory::class)->put(MemoryScope::Run, $response->runId, 'probe-key', 'mutated-value');

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');

    // Retry branch 1: agent must see the frozen snapshot value.
    (new AdvanceDurableBranch($response->runId, 'parallel:1'))->handle($manager);

    expect(MemorySpyFlakyAgent::$attempts)->toBe(2)
        ->and(MemorySpyFlakyAgent::$seenValues[2])->toBe('initial-value');

    // Complete the synthesis step so the run closes cleanly.
    (new AdvanceDurableSwarm($response->runId, 2))->handle($manager);

    expect($manager->find($response->runId)['status'])->toBe('completed');
});

test('hierarchical replay coordinator serves frozen snapshot to retried worker agent', function () {
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('spy_node', [
            'spy_node' => [
                'type' => 'worker',
                'agent' => MemorySpyFlakyAgent::class,
                'prompt' => 'spy-worker-task',
            ],
        ]),
    ]);

    $response = ReplayDeterminismHierarchicalSwarm::make()->dispatchDurable('replay-determinism-hierarchical-task');
    $manager = app(DurableSwarmManager::class);

    // Write the initial probe value before anything executes.
    app(SwarmMemory::class)->put(MemoryScope::Run, $response->runId, 'probe-key', 'initial-value');
    MemorySpyFlakyAgent::reset($response->runId);

    // Step 0: coordinator runs, plans worker route.
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    // Step 1: worker (MemorySpyFlakyAgent) crashes on first attempt.
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);

    expect(MemorySpyFlakyAgent::$seenValues[1])->toBe('initial-value');

    // Mutate memory after the crash.
    app(SwarmMemory::class)->put(MemoryScope::Run, $response->runId, 'probe-key', 'mutated-value');

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');

    // Step 1 retry: worker must see the frozen snapshot value.
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);

    expect(MemorySpyFlakyAgent::$attempts)->toBe(2)
        ->and(MemorySpyFlakyAgent::$seenValues[2])->toBe('initial-value')
        ->and($manager->find($response->runId)['status'])->toBe('completed');
});

test('FreshExecution replay mode bypasses frozen snapshot and sees live memory on retry', function () {
    $response = FreshExecutionReplaySwarm::make()->dispatchDurable('fresh-execution-replay-task');
    $manager = app(DurableSwarmManager::class);

    app(SwarmMemory::class)->put(MemoryScope::Run, $response->runId, 'probe-key', 'initial-value');
    MemorySpyFlakyAgent::reset($response->runId);

    // First advance: agent crashes.
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect(MemorySpyFlakyAgent::$seenValues[1])->toBe('initial-value');

    // Mutate memory after the crash.
    app(SwarmMemory::class)->put(MemoryScope::Run, $response->runId, 'probe-key', 'mutated-value');

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');

    // Retry: FreshExecution mode does NOT freeze memory, so the agent sees the
    // live mutated value rather than the snapshot.
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    expect(MemorySpyFlakyAgent::$attempts)->toBe(2)
        ->and(MemorySpyFlakyAgent::$seenValues[2])->toBe('mutated-value')
        ->and($manager->find($response->runId)['status'])->toBe('completed');
});
