<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ClaimsQueuedRunExecution;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
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

function qhpcParallelInLoopPlan(): array
{
    return [
        'start_at' => 'gather_node',
        'nodes' => [
            'gather_node' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'gather',
                'next' => 'parallel_node',
            ],
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'join_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'write-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'edit-branch',
            ],
            'join_node' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'join',
                'with_outputs' => [
                    'draft' => 'writer_node',
                    'edit' => 'editor_node',
                ],
                'next' => 'finish_node',
                'loop' => [
                    'to' => 'gather_node',
                    'max_iterations' => 3,
                ],
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'join_node',
            ],
        ],
    ];
}

/**
 * Drive a queued multi_worker run with a parallel-in-loop plan to a terminal
 * state, dispatching branch jobs and resuming across each loop pass. Returns the
 * number of real branch jobs dispatched (one AdvanceDurableBranch per pending
 * branch row), which equals 2 branches × 3 passes = 6 when the fan-out genuinely
 * re-runs every iteration.
 */
function driveQueuedParallelInLoop(string $runId): int
{
    $manager = app(DurableSwarmManager::class);
    $dispatched = 0;
    $guard = 0;

    while (true) {
        if ($guard++ > 200) {
            throw new RuntimeException('Queued parallel-in-loop run did not converge.');
        }

        $run = $manager->find($runId);

        if (in_array($run['status'], ['completed', 'failed', 'cancelled'], true)) {
            break;
        }

        if ($run['status'] === 'waiting') {
            $branches = app(DurableRunStore::class)->branchesFor($runId, $run['current_node_id']);

            foreach ($branches as $branch) {
                if (($branch['status'] ?? null) === 'pending' || ($branch['status'] ?? null) === null) {
                    $dispatched++;
                    (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle($manager);
                }
            }

            (new ResumeQueuedHierarchicalSwarm($runId))->handle(app(QueuedHierarchicalCoordinator::class));

            continue;
        }

        throw new RuntimeException("Unexpected queued run status [{$run['status']}] for run [{$runId}].");
    }

    return $dispatched;
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

test('queued hierarchical resume fails loud on an undecryptable context input (#212 T3)', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(SwarmRunner::class);

    $context = RunContext::from('queued-hierarchical-task', 'qhpc-poison-1');
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    $manager = app(DurableSwarmManager::class);
    foreach (DB::table('swarm_durable_branches')->where('run_id', 'qhpc-poison-1')->get() as $branch) {
        (new AdvanceDurableBranch('qhpc-poison-1', (string) $branch->branch_id))->handle($manager);
    }

    // Corrupt the persisted resume context (rotated/wrong APP_KEY).
    DB::table('swarm_contexts')->where('run_id', 'qhpc-poison-1')
        ->update(['input' => 'sw0:'.base64_encode('not-real-ciphertext')]);

    // The queued-hierarchical resume reads the run's top-level context through the strict F3 path
    // (DatabaseContextStore::find); an undecryptable input fails loud (SwarmException) so the run is
    // re-dispatchable, rather than resuming the join step from a null/ciphertext prompt.
    expect(fn () => app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin('qhpc-poison-1'))
        ->toThrow(SwarmException::class, 'verify APP_KEY');
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

test('queued hierarchical parallel multi_worker re-runs the fan-out branch agents on every loop pass', function () {
    // 3 passes × (gather + 2 branches + join) + coordinator = 13 executions.
    config()->set('swarm.max_agent_steps', 20);
    FakeHierarchicalCoordinator::fake([qhpcParallelInLoopPlan()]);

    // Count REAL branch-agent invocations via closure fakes — the load-bearing
    // signal that the fan-out actually re-executed each pass (executed_node_ids and
    // parallel_groups are re-appended on the join even when the agents do not run).
    $writeRuns = 0;
    $editRuns = 0;
    FakeResearcher::fake(array_fill(0, 10, 'gather-out'));
    FakeWriter::fake(function () use (&$writeRuns): string {
        $writeRuns++;

        return 'write-out';
    });
    FakeEditor::fake(function () use (&$editRuns): string {
        $editRuns++;

        return 'edit-out';
    });

    $runId = 'qhpc-parallel-in-loop-1';
    $context = RunContext::from('queued-parallel-in-loop', $runId);
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    $dispatched = driveQueuedParallelInLoop($runId);

    // The decisive correctness signal: every branch agent ran once per pass × 3
    // passes (2 × 3 = 6 real branch dispatches). Pre-fix the queued resume restarted
    // the join loop from iteration 1 on every pass and never reached the bound, so
    // the run failed to converge (an infinite re-fan-out) — the queued manifestation
    // of the same parallel-in-loop defect as H1. These assertions count actual agent
    // execution, not executed_node_ids bookkeeping, so a green run cannot mask a
    // fan-out that silently stopped re-running.
    expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed')
        ->and($writeRuns)->toBe(3)
        ->and($editRuns)->toBe(3)
        ->and($dispatched)->toBe(6);
});

/**
 * Drive a queued multi_worker run to the post-join continuation point: invoke the
 * swarm, advance every parallel branch to completion, then acquire the continuation
 * lease so the history row sits in 'running'. Returns the acquisition token. The run
 * is now exactly where a worker would crash after grabbing the continuation lease.
 */
function driveToContinuationLease(string $runId): string
{
    $context = RunContext::from('queued-hierarchical-task', $runId);
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    $manager = app(DurableSwarmManager::class);
    foreach (DB::table('swarm_durable_branches')->where('run_id', $runId)->get() as $branch) {
        (new AdvanceDurableBranch($runId, (string) $branch->branch_id))->handle($manager);
    }

    /** @var ClaimsQueuedRunExecution $store */
    $store = app(RunHistoryStore::class);
    $acquisition = $store->acquireQueuedRunContinuationLease($runId, 3600, 300);

    expect($acquisition->outcome)->toBe('fresh');

    return (string) $acquisition->executionToken;
}

/** Expire the queued continuation lease on the stranded history row. */
function expireContinuationLease(string $runId): void
{
    DB::table('swarm_run_histories')
        ->where('run_id', $runId)
        ->update(['leased_until' => now('UTC')->subMinutes(10)]);
}

test('acquireQueuedRunContinuationLease reclaims a stranded running run with a fresh token', function () {
    $runId = 'qhpc-reclaim-1';
    $deadToken = driveToContinuationLease($runId);
    expireContinuationLease($runId);

    /** @var ClaimsQueuedRunExecution $store */
    $store = app(RunHistoryStore::class);
    $reclaim = $store->acquireQueuedRunContinuationLease($runId, 3600, 300);

    expect($reclaim->outcome)->toBe('reclaimed')
        ->and($reclaim->executionToken)->not->toBe($deadToken)
        ->and($reclaim->acquired())->toBeTrue();

    // Status stays 'running', the token rotated, and the lease is freshly extended.
    $row = DB::table('swarm_run_histories')->where('run_id', $runId)->first();
    expect($row->status)->toBe('running')
        ->and($row->execution_token)->toBe($reclaim->executionToken)
        ->and($row->execution_token)->not->toBe($deadToken)
        ->and(now('UTC')->lt(Carbon::parse($row->leased_until)))->toBeTrue();
});

test('resumeQueuedHierarchicalAfterJoin reclaims a stranded run and drives it to completion', function () {
    $runId = 'qhpc-reclaim-resume-1';
    driveToContinuationLease($runId);

    // The first worker died holding the lease. Once it expires, the redispatched
    // resume job re-enters resumeQueuedHierarchicalAfterJoin, whose internal
    // acquireQueuedRunContinuationLease hits the reclaim branch (running + expired)
    // and drives the post-join continuation to completion.
    expireContinuationLease($runId);

    expect(app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId))->not->toBeNull();
    expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
});

test('acquireQueuedRunContinuationLease does not reclaim a non-expired running run (F-D2 single-reclaim)', function () {
    $runId = 'qhpc-reclaim-fd2-1';
    driveToContinuationLease($runId);
    expireContinuationLease($runId);

    /** @var ClaimsQueuedRunExecution $store */
    $store = app(RunHistoryStore::class);

    // Two back-to-back recovery sweeps race on the same stranded run. lockForUpdate
    // serializes them: the first reclaims (rotating the token + extending the lease),
    // the second sees a now-non-expired lease and reports duplicate_running.
    $first = $store->acquireQueuedRunContinuationLease($runId, 3600, 300);
    $second = $store->acquireQueuedRunContinuationLease($runId, 3600, 300);

    expect($first->outcome)->toBe('reclaimed')
        ->and($second->outcome)->toBe('duplicate_running');

    // Exactly one live token owns the run.
    $row = DB::table('swarm_run_histories')->where('run_id', $runId)->first();
    expect($row->execution_token)->toBe($first->executionToken);
});

test('reclaim never lets a stale worker double-drive the continuation (F-D1)', function () {
    $runId = 'qhpc-reclaim-fd1-1';
    $staleToken = driveToContinuationLease($runId);
    expireContinuationLease($runId);

    /** @var ClaimsQueuedRunExecution $store */
    $store = app(RunHistoryStore::class);
    $reclaim = $store->acquireQueuedRunContinuationLease($runId, 3600, 300);
    expect($reclaim->outcome)->toBe('reclaimed');

    // The stale worker (still holding $staleToken) now tries to record a step. Its
    // lease-guarded write matches zero rows because the token rotated, so it aborts
    // with LostSwarmLeaseException instead of double-driving the post-join continuation.
    $step = new SwarmStep(
        agentClass: FakeWriter::class,
        input: 'stale-input',
        output: 'stale-output',
        artifacts: [],
        metadata: ['index' => 99],
    );

    expect(fn () => $store->recordStep($runId, $step, 3600, $staleToken, 300))
        ->toThrow(LostSwarmLeaseException::class);

    // The reclaim left no double-dispatch behind: the run is still 'running' under the
    // fresh token (not corrupted by the stale write) and resumes cleanly to completion.
    expect(DB::table('swarm_run_histories')->where('run_id', $runId)->value('status'))->toBe('running');

    expireContinuationLease($runId);
    expect(app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId))->not->toBeNull();
    expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
});

test('recoverableQueuedResumes finds only the stranded running queued-hierarchical run', function () {
    // Three InvokeSwarm calls in this test each consume one coordinator plan.
    FakeHierarchicalCoordinator::fake([qhpcParallelPlan(), qhpcParallelPlan(), qhpcParallelPlan()]);
    FakeWriter::fake(['writer-out', 'writer-out', 'writer-out']);
    FakeEditor::fake(['editor-out', 'editor-out', 'editor-out']);

    $strandedId = 'qhpc-sweep-stranded-1';
    driveToContinuationLease($strandedId);
    expireContinuationLease($strandedId);

    // A still-leased (non-expired) stranded run must be excluded.
    $freshLeaseId = 'qhpc-sweep-fresh-lease-1';
    driveToContinuationLease($freshLeaseId);

    // A run still in 'waiting' (branches not yet joined) must be excluded.
    $waitingId = 'qhpc-sweep-waiting-1';
    $context = RunContext::from('queued-hierarchical-task', $waitingId);
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));
    expect(app(RunHistoryStore::class)->find($waitingId)['status'])->toBe('waiting');

    $store = app(DurableRunStore::class);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $recoverable = $store->recoverableQueuedResumes(graceSeconds: 300);

    // O(1): a single SELECT, no per-candidate find() fan-out.
    expect($queries)->toBe(1);

    $runIds = array_map(static fn (array $run): string => $run['run_id'], $recoverable);
    expect($runIds)->toBe([$strandedId])
        ->and($runIds)->not->toContain($freshLeaseId)
        ->and($runIds)->not->toContain($waitingId);

    // The hydrated shape carries the routing the recovery coordinator dispatches by.
    expect($recoverable[0]['coordination_profile'])->toBe(CoordinationProfile::QueueHierarchicalParallel->value);
});

test('recoverableQueuedResumes excludes finished runs and returns [] with no candidates', function () {
    $store = app(DurableRunStore::class);

    // Empty candidate set short-circuits to [] in a single query.
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    expect($store->recoverableQueuedResumes(graceSeconds: 300))->toBe([]);
    expect($queries)->toBe(1);

    // A completed run (finished_at set) is never recoverable, even with an expired lease.
    $finishedId = 'qhpc-sweep-finished-1';
    driveToContinuationLease($finishedId);
    expireContinuationLease($finishedId);
    app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($finishedId);
    expect(app(RunHistoryStore::class)->find($finishedId)['status'])->toBe('completed');

    expect($store->recoverableQueuedResumes(graceSeconds: 300))->toBe([]);
});

test('swarm recover re-dispatches a queued-hierarchical resume stranded in running', function () {
    Bus::fake([ResumeQueuedHierarchicalSwarm::class]);

    $runId = 'qhpc-recover-stranded-1';
    driveToContinuationLease($runId);
    expireContinuationLease($runId);

    Artisan::call('swarm:recover');

    Bus::assertDispatched(
        ResumeQueuedHierarchicalSwarm::class,
        fn (ResumeQueuedHierarchicalSwarm $job): bool => $job->runId === $runId,
    );

    // The recovery sweep bumped the run's recovery bookkeeping.
    $history = DB::table('swarm_run_histories')->where('run_id', $runId)->first();
    expect($history->status)->toBe('running');
    expect((int) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('recovery_count'))->toBeGreaterThanOrEqual(1);
});
