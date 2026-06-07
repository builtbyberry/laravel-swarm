<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalChainSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalParallelWithSynthesisSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalSingleWorkerSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('durable static hierarchical single-worker dispatch creates run and completes', function () {
    $response = FakeStaticHierarchicalSingleWorkerSwarm::make()->dispatchDurable('durable-static-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    expect($response)->toBeInstanceOf(DurableSwarmResponse::class)
        ->and($manager->find($runId)['status'])->toBe('pending')
        ->and($manager->find($runId)['next_step_index'])->toBe(0);

    // Step 0: plan init — no agent called, cursor at writer_node
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    FakeWriter::assertNeverPrompted();

    $run = $manager->find($runId);
    expect($run['status'])->toBe('pending')
        ->and($run['next_step_index'])->toBe(1)
        ->and($run['route_cursor']['current_node_id'])->toBe('writer_node')
        ->and($run['route_cursor']['coordinator_agent_class'])->toBe('');

    // Step 1: execute writer_node → complete
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    FakeWriter::assertPrompted('static-writer-task');

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['status'])->toBe('completed')
        ->and($history['output'])->toBe('writer-out')
        ->and($history['steps'])->toHaveCount(1)
        ->and($history['context']['metadata']['topology'])->toBe('static_hierarchical');
});

test('durable static hierarchical step-0 persists empty coordinator_agent_class in route_cursor', function () {
    $response = FakeStaticHierarchicalSingleWorkerSwarm::make()->dispatchDurable('cursor-assert-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    $rawCursor = DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->value('route_cursor');

    $cursor = json_decode($rawCursor, true);

    expect($cursor)->toHaveKey('coordinator_agent_class')
        ->and($cursor['coordinator_agent_class'])->toBe('');
});

test('durable static hierarchical two-step chain executes workers in order', function () {
    $response = FakeStaticHierarchicalChainSwarm::make()->dispatchDurable('chain-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    // Step 0: init — researcher_node is first worker
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    FakeResearcher::assertNeverPrompted();
    FakeWriter::assertNeverPrompted();

    $run = $manager->find($runId);
    expect($run['route_cursor']['current_node_id'])->toBe('researcher_node')
        ->and($run['next_step_index'])->toBe(1);

    // Step 1: researcher_node
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    FakeResearcher::assertPrompted('research-task');
    FakeWriter::assertNeverPrompted();

    expect($manager->find($runId)['route_cursor']['current_node_id'])->toBe('writer_node')
        ->and($manager->find($runId)['next_step_index'])->toBe(2);

    // Step 2: writer_node → complete
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    FakeWriter::assertPrompted(<<<'PROMPT'
write-task

Named outputs:
[research]
research-out
PROMPT);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['output'])->toBe('writer-out')
        ->and($history['steps'])->toHaveCount(2)
        ->and($history['context']['metadata']['executed_node_ids'])->toBe(['researcher_node', 'writer_node', 'finish']);
});

test('durable static hierarchical parallel branches fan out and synthesize', function () {
    $response = FakeStaticHierarchicalParallelWithSynthesisSwarm::make()->dispatchDurable('parallel-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    // Step 0: init — cursor lands at parallel_gather (a parallel node)
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    FakeResearcher::assertNeverPrompted();
    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();

    expect($manager->find($runId)['route_cursor']['current_node_id'])->toBe('parallel_gather');

    // Step 1: parallel_gather dispatch — creates 2 branches, run goes to waiting
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    $branches = app(DurableRunStore::class)->branchesFor($runId, 'parallel_gather');

    expect($manager->find($runId)['status'])->toBe('waiting')
        ->and($branches)->toHaveCount(2);

    foreach ($branches as $branch) {
        (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
    }

    FakeResearcher::assertPrompted('research-task');
    FakeWriter::assertPrompted('write-task');

    expect($manager->find($runId)['status'])->toBe('pending');

    // Join step: parallel_gather rejoins both branches, cursor advances to editor_node — no agent call
    $joinStep = $manager->find($runId)['next_step_index'];
    (new AdvanceDurableSwarm($runId, $joinStep))->handle($manager);

    FakeEditor::assertNeverPrompted();
    expect($manager->find($runId)['status'])->toBe('pending');

    // Editor step: editor_node executes with both branch outputs → complete
    $editorStep = $manager->find($runId)['next_step_index'];
    (new AdvanceDurableSwarm($runId, $editorStep))->handle($manager);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['output'])->toBe('editor-out')
        ->and($history['context']['metadata']['topology'])->toBe('static_hierarchical');
});

test('durable static hierarchical crash-replay is idempotent at worker step', function () {
    // Use the chain swarm so step-1 (researcher_node) is not the final step.
    // The beforeStepCheckpoint hook only fires on non-terminal steps because terminal
    // steps go directly to completeRun() bypassing checkpointAndDispatch().
    $response = FakeStaticHierarchicalChainSwarm::make()->dispatchDurable('replay-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    // Step 0: init — cursor lands at researcher_node
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    expect($manager->find($runId)['route_cursor']['current_node_id'])->toBe('researcher_node');

    // Step 1: researcher_node executes then crashes before checkpoint is committed
    $manager->beforeStepCheckpointForTesting(function (): void {
        throw new RuntimeException('Simulated crash before checkpoint.');
    });

    expect(fn () => (new AdvanceDurableSwarm($runId, 1))->handle($manager))
        ->toThrow(RuntimeException::class, 'Simulated crash before checkpoint.');

    $manager->beforeStepCheckpointForTesting(null);

    expect($manager->find($runId)['status'])->toBe('running')
        ->and($manager->find($runId)['next_step_index'])->toBe(1);

    // Expire the lease so step-1 can re-run
    DB::table('swarm_durable_runs')
        ->where('run_id', $runId)
        ->update(['leased_until' => now()->subSecond()]);

    // Re-advance step-1 — researcher_node re-executes (no snapshot was persisted on crash)
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    FakeResearcher::assertPrompted('research-task');

    expect($manager->find($runId)['route_cursor']['current_node_id'])->toBe('writer_node')
        ->and($manager->find($runId)['next_step_index'])->toBe(2);

    // Step 2: writer_node → complete
    (new AdvanceDurableSwarm($runId, 2))->handle($manager);

    $history = app(SwarmHistory::class)->find($runId);

    expect($manager->find($runId)['status'])->toBe('completed')
        ->and($history['steps'])->toHaveCount(2)
        ->and($history['output'])->toBe('writer-out');
});
