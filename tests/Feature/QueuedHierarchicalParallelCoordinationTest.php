<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

beforeEach(function () {
    configureQueuedHierarchicalParallelRuntime();
    FakeHierarchicalCoordinator::fake([qhpcParallelPlan()]);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
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

    FakeWriter::assertPrompted('writer-branch');
    FakeEditor::assertPrompted('editor-branch');
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

test('queued hierarchical parallel multi_worker can be cancelled while waiting on branches', function () {
    $context = RunContext::from('queued-hierarchical-task', 'qhpc-cancel-1');
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    app(DurableSwarmManager::class)->cancel('qhpc-cancel-1');

    $history = app(RunHistoryStore::class)->find('qhpc-cancel-1');
    expect($history['status'])->toBe('cancelled');
});
