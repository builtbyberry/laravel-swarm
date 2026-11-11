<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ClaimsQueuedRunExecution;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\ReleasesWaitingRunForFailure;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\DurableDispatchType;
use BuiltByBerry\LaravelSwarm\Events\SwarmChildCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableChildSwarmCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJoinRelease;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRunContext;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FailingPromptAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\DecoratingDurableRunStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\FailingFindContextStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\HookedContinuationRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\MarkerFailingDurableRunStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalParallelFailBranchSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelFailingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * A durable parent that sits at a branch join while it also has an open wait (a
 * signal wait, a child swarm's wait) is not released to its join until every wait
 * is closed (#600). Covers the three ways a parent reaches its join — the step-durable
 * top-level parallel and hierarchical profiles and the queued-hierarchical multi_worker
 * profile — and every path that used to release it early: branch completion, resume,
 * and swarm:recover.
 */
function joinWaitRuntime(bool $queuedHierarchical = false): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    if ($queuedHierarchical) {
        // Branch and resume jobs never run on their own; each test drives them.
        config()->set('queue.default', 'null');
        config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');
    }

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(QueuedHierarchicalCoordinator::class);
    app()->forgetInstance(DurableSwarmManager::class);
}

/**
 * How many dispatches of each kind the run has had written to the durable outbox.
 * Eager dispatch is off in these tests, so every step, branch and queued-resume
 * dispatch leaves exactly one outbox row.
 *
 * @return array{step: int, queued_resume: int}
 */
function joinWaitDispatches(string $runId): array
{
    $count = fn (DurableDispatchType $type): int => DB::table('swarm_durable_outbox')
        ->where('run_id', $runId)
        ->where('dispatch_type', $type->value)
        ->count();

    return [
        'step' => $count(DurableDispatchType::Step),
        'queued_resume' => $count(DurableDispatchType::QueuedResume),
    ];
}

function joinWaitAgeRun(string $runId): void
{
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['updated_at' => now('UTC')->subMinutes(10)]);
}

/**
 * Every branch of the run written terminal straight to the table: no branch
 * completion runs, so nothing dispatches the join.
 */
function joinWaitMarkBranchesCompleted(string $runId): void
{
    DB::table('swarm_durable_branches')->where('run_id', $runId)->update([
        'status' => 'completed',
        'output' => 'branch-out',
        'usage' => json_encode([]),
        'duration_ms' => 1,
        'finished_at' => now('UTC'),
        'execution_token' => null,
        'leased_until' => null,
    ]);
}

function joinWaitAgeBranches(string $runId): void
{
    DB::table('swarm_durable_branches')->where('run_id', $runId)->update(['updated_at' => now('UTC')->subMinutes(10)]);
}

/**
 * A top-level parallel run at its join (step-durable), every branch advanced to
 * completion while an app wait was open.
 */
function joinWaitParallelRun(bool $completeBranches = true): string
{
    $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    expect($manager->find($runId)['status'])->toBe('waiting');

    $manager->wait($runId, 'approval', 'needs approval');

    if ($completeBranches) {
        foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel') as $branch) {
            (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
        }
    }

    return $runId;
}

function joinWaitHierarchicalPlan(): array
{
    return [
        'start_at' => 'parallel_node',
        'nodes' => [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
            'editor_node' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor-branch'],
            'finish_node' => ['type' => 'finish', 'output_from' => 'editor_node'],
        ],
    ];
}

/**
 * A queued-hierarchical multi_worker run at its join, every branch advanced to
 * completion while an app wait was open.
 */
function joinWaitQueuedRun(string $runId, bool $completeBranches = true): void
{
    $context = RunContext::from('queued-hierarchical-task', $runId);
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));

    $manager = app(DurableSwarmManager::class);
    expect($manager->find($runId)['status'])->toBe('waiting')
        ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting');

    $manager->wait($runId, 'approval', 'needs approval');

    if ($completeBranches) {
        foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel_node') as $branch) {
            (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle($manager);
        }
    }
}

function joinWaitResumeQueued(string $runId): void
{
    (new ResumeQueuedHierarchicalSwarm($runId))->handle(app(QueuedHierarchicalCoordinator::class));
}

describe('step-durable top-level parallel', function () {
    beforeEach(function () {
        joinWaitRuntime();
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        FakeResearcher::fake(['research-out']);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
    });

    test('branch completion does not release the join while a wait is open; the signal does', function () {
        $runId = joinWaitParallelRun();
        $manager = app(DurableSwarmManager::class);
        $before = joinWaitDispatches($runId);

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and($manager->inspect($runId)->waits[0]['status'])->toBe('waiting');

        $signal = $manager->signal($runId, 'approval', ['approved' => true]);

        expect($signal->accepted)->toBeTrue()
            ->and($manager->find($runId)['status'])->toBe('pending')
            ->and($manager->find($runId)['next_step_index'])->toBe(3)
            ->and(joinWaitDispatches($runId)['step'])->toBe($before['step'] + 1);

        (new AdvanceDurableSwarm($runId, 3))->handle($manager);

        expect($manager->find($runId)['status'])->toBe('completed')
            ->and(app(RunHistoryStore::class)->find($runId)['output'])->toBe("research-out\n\nwriter-out\n\neditor-out");
    });

    test('resume does not release the join while a wait is open', function () {
        $runId = joinWaitParallelRun(completeBranches: false);
        $manager = app(DurableSwarmManager::class);

        // Branches reach their terminal state while the run is paused, so no branch
        // completion dispatches anything: resume is the only path left to the join.
        $manager->pause($runId);
        joinWaitMarkBranchesCompleted($runId);
        $before = joinWaitDispatches($runId);

        $result = $manager->resume($runId);

        expect($result->isWaiting())->toBeTrue()
            ->and($manager->find($runId)['status'])->toBe('waiting')
            ->and(joinWaitDispatches($runId))->toBe($before);

        $manager->signal($runId, 'approval');

        expect($manager->find($runId)['status'])->toBe('pending')
            ->and(joinWaitDispatches($runId)['step'])->toBe($before['step'] + 1);

        (new AdvanceDurableSwarm($runId, 3))->handle($manager);

        expect($manager->find($runId)['status'])->toBe('completed');
    });

    test('swarm:recover does not release the join while a wait is open, sweep after sweep', function () {
        $runId = joinWaitParallelRun(completeBranches: false);
        $manager = app(DurableSwarmManager::class);

        // Terminal branches written straight to the table: no branch completion runs,
        // so recovery is the only path left to the join.
        joinWaitMarkBranchesCompleted($runId);
        joinWaitAgeBranches($runId);
        joinWaitAgeRun($runId);
        $before = joinWaitDispatches($runId);

        expect(app(DurableRunStore::class)->recoverableWaitingJoins(runId: $runId))->toBe([]);

        Artisan::call('swarm:recover');
        Artisan::call('swarm:recover');

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and((int) ($manager->find($runId)['recovery_count'] ?? 0))->toBe(0)
            ->and(joinWaitDispatches($runId))->toBe($before);

        $manager->signal($runId, 'approval');
        (new AdvanceDurableSwarm($runId, 3))->handle($manager);

        expect($manager->find($runId)['status'])->toBe('completed');
    });

    test('a signal that arrives before the branches finish leaves the run waiting for them', function () {
        $runId = joinWaitParallelRun(completeBranches: false);
        $manager = app(DurableSwarmManager::class);
        $before = joinWaitDispatches($runId);

        $manager->signal($runId, 'approval');

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
            ->and(joinWaitDispatches($runId))->toBe($before);

        foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel') as $branch) {
            (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
        }

        expect($manager->find($runId)['status'])->toBe('pending')
            ->and(joinWaitDispatches($runId)['step'])->toBe($before['step'] + 1);

        (new AdvanceDurableSwarm($runId, 3))->handle($manager);

        expect($manager->find($runId)['status'])->toBe('completed');
    });

    test('accepting one of two signals keeps history waiting; the second releases the join', function () {
        $runId = joinWaitParallelRun();
        $manager = app(DurableSwarmManager::class);
        $manager->wait($runId, 'legal', 'needs legal');
        $before = joinWaitDispatches($runId);

        $manager->signal($runId, 'approval');

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
            ->and(joinWaitDispatches($runId))->toBe($before);

        $manager->signal($runId, 'legal');

        expect($manager->find($runId)['status'])->toBe('pending')
            ->and(joinWaitDispatches($runId)['step'])->toBe($before['step'] + 1);

        (new AdvanceDurableSwarm($runId, 3))->handle($manager);

        expect($manager->find($runId)['status'])->toBe('completed');
    });

    test('the store refuses to release a join while a wait is open', function () {
        $runId = joinWaitParallelRun(completeBranches: false);
        $store = app(DurableRunStore::class);

        expect($store->releaseWaitingRunForJoin($runId, 3))->toBeFalse()
            ->and($store->find($runId)['status'])->toBe('waiting');

        app(DurableSwarmManager::class)->signal($runId, 'approval');

        // The run stays waiting at its branch boundary after the wait closes, so the
        // join release is reachable — and now allowed.
        expect($store->find($runId)['status'])->toBe('waiting')
            ->and($store->releaseWaitingRunForJoin($runId, 3))->toBeTrue()
            ->and($store->find($runId)['status'])->toBe('pending');
    });

    test('recovery skips runs with an open wait before applying its limit', function () {
        $manager = app(DurableSwarmManager::class);
        $blocked = [];

        foreach ([1, 2] as $i) {
            $runId = joinWaitParallelRun(completeBranches: false);
            DB::table('swarm_durable_branches')->where('run_id', $runId)->update(['status' => 'completed', 'output' => 'x', 'finished_at' => now('UTC')]);
            DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['updated_at' => now('UTC')->subMinutes(30 - $i)]);
            $blocked[] = $runId;
        }

        $eligible = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
        (new AdvanceDurableSwarm($eligible, 0))->handle($manager);
        DB::table('swarm_durable_branches')->where('run_id', $eligible)->update(['status' => 'completed', 'output' => 'x', 'finished_at' => now('UTC')]);
        joinWaitAgeRun($eligible);

        $selected = array_column(app(DurableRunStore::class)->recoverableWaitingJoins(limit: 1), 'run_id');

        expect($selected)->toBe([$eligible]);
    });
});

describe('step-durable hierarchical', function () {
    beforeEach(function () {
        joinWaitRuntime();
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        FakeHierarchicalCoordinator::fake([joinWaitHierarchicalPlan()]);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
    });

    test('branch completion does not release the hierarchical join while a wait is open; the signal does', function () {
        $runId = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task')->runId;
        $manager = app(DurableSwarmManager::class);

        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
        (new AdvanceDurableSwarm($runId, 1))->handle($manager);
        expect($manager->find($runId)['status'])->toBe('waiting');

        $manager->wait($runId, 'approval');

        foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel_node') as $branch) {
            (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
        }

        $before = joinWaitDispatches($runId);

        expect($manager->find($runId)['status'])->toBe('waiting');

        $manager->signal($runId, 'approval');

        expect($manager->find($runId)['status'])->toBe('pending')
            ->and(joinWaitDispatches($runId)['step'])->toBe($before['step'] + 1);

        (new AdvanceDurableSwarm($runId, 3))->handle($manager);

        expect($manager->find($runId)['status'])->toBe('completed')
            ->and(app(RunHistoryStore::class)->find($runId)['output'])->toBe('editor-out');
    });
});

describe('queued-hierarchical multi_worker', function () {
    beforeEach(function () {
        joinWaitRuntime(queuedHierarchical: true);
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        FakeHierarchicalCoordinator::fake([joinWaitHierarchicalPlan()]);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
    });

    test('branch completion does not release the queued join while a wait is open; the signal runs it to completion', function () {
        $runId = 'join-wait-qh-1';
        joinWaitQueuedRun($runId);
        $manager = app(DurableSwarmManager::class);

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
            ->and(joinWaitDispatches($runId)['queued_resume'])->toBe(0);

        $signal = $manager->signal($runId, 'approval');

        expect($signal->accepted)->toBeTrue()
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
            ->and($manager->find($runId)['status'])->toBe('pending')
            ->and(joinWaitDispatches($runId))->toBe(['step' => 0, 'queued_resume' => 1]);

        joinWaitResumeQueued($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed')
            ->and(app(RunHistoryStore::class)->find($runId)['output'])->toBe('editor-out');
    });

    test('resume does not release the queued join while a wait is open', function () {
        $runId = 'join-wait-qh-resume';
        joinWaitQueuedRun($runId, completeBranches: false);
        $manager = app(DurableSwarmManager::class);

        $manager->pause($runId);
        joinWaitMarkBranchesCompleted($runId);

        $manager->resume($runId);

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and(joinWaitDispatches($runId)['queued_resume'])->toBe(0);

        $manager->signal($runId, 'approval');

        expect(joinWaitDispatches($runId)['queued_resume'])->toBe(1);

        joinWaitResumeQueued($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
    });

    test('a signal accepted while paused before the branches finish leaves the join to the last branch after resume (#601)', function () {
        $runId = 'join-wait-qh-paused-signal-open-branches';
        joinWaitQueuedRun($runId, completeBranches: false);
        $manager = app(DurableSwarmManager::class);

        $manager->pause($runId);
        $signal = $manager->signal($runId, 'approval');

        expect($signal->accepted)->toBeTrue()
            ->and($manager->find($runId)['status'])->toBe('paused')
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('paused')
            ->and(joinWaitDispatches($runId))->toBe(['step' => 0, 'queued_resume' => 0]);

        $result = $manager->resume($runId);

        // Still waiting on its branches: the join is not released early.
        expect($result->isWaiting())->toBeTrue()
            ->and($manager->find($runId)['status'])->toBe('waiting')
            ->and(joinWaitDispatches($runId))->toBe(['step' => 0, 'queued_resume' => 0]);

        foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel_node') as $branch) {
            (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle($manager);
        }

        expect(joinWaitDispatches($runId))->toBe(['step' => 0, 'queued_resume' => 1]);

        joinWaitResumeQueued($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
    });

    test('a signal accepted while paused after the branches finished releases the join on resume (#601)', function () {
        $runId = 'join-wait-qh-paused-signal-done-branches';
        joinWaitQueuedRun($runId, completeBranches: false);
        $manager = app(DurableSwarmManager::class);

        $manager->pause($runId);
        joinWaitMarkBranchesCompleted($runId);

        expect($manager->signal($runId, 'approval')->accepted)->toBeTrue()
            ->and($manager->find($runId)['status'])->toBe('paused')
            ->and(joinWaitDispatches($runId))->toBe(['step' => 0, 'queued_resume' => 0]);

        $result = $manager->resume($runId);

        expect($result->isWaiting())->toBeTrue()
            ->and($result->waitingBoundaryDispatched)->toBeTrue()
            ->and(joinWaitDispatches($runId))->toBe(['step' => 0, 'queued_resume' => 1]);

        joinWaitResumeQueued($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
    });

    test('swarm:recover does not release the queued join while a wait is open', function () {
        $runId = 'join-wait-qh-recover';
        joinWaitQueuedRun($runId, completeBranches: false);
        $manager = app(DurableSwarmManager::class);

        joinWaitMarkBranchesCompleted($runId);
        joinWaitAgeBranches($runId);
        joinWaitAgeRun($runId);

        Artisan::call('swarm:recover');
        Artisan::call('swarm:recover');

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and((int) ($manager->find($runId)['recovery_count'] ?? 0))->toBe(0)
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting');

        $manager->signal($runId, 'approval');
        joinWaitResumeQueued($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
    });

    test('a signal that arrives before the branches finish leaves the queued run waiting for them', function () {
        $runId = 'join-wait-qh-early';
        joinWaitQueuedRun($runId, completeBranches: false);
        $manager = app(DurableSwarmManager::class);

        $manager->signal($runId, 'approval');

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
            ->and(joinWaitDispatches($runId))->toBe(['step' => 0, 'queued_resume' => 0]);

        foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel_node') as $branch) {
            (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle($manager);
        }

        expect(joinWaitDispatches($runId))->toBe(['step' => 0, 'queued_resume' => 1]);

        joinWaitResumeQueued($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
    });
});

/**
 * A logger whose error() — the "branch failed" log the branch worker writes between
 * persisting the failure and failing the parent — runs $onBranchFailed first, or
 * throws when $throw is set (a worker that never reaches the parent).
 */
function joinWaitBranchFailureLogger(?Closure $onBranchFailed = null, bool $throw = false): void
{
    joinWaitBranchFailureHookRan(reset: true);
    $logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $logger->shouldReceive('error')->andReturnUsing(function (string|Stringable $message) use ($onBranchFailed, $throw): void {
        if (! str_contains((string) $message, 'branch failed')) {
            return;
        }

        joinWaitBranchFailureHookRan(ran: true);

        if ($onBranchFailed !== null) {
            $onBranchFailed();
        }

        if ($throw) {
            throw new RuntimeException('logging unavailable');
        }
    });
    app()->instance(LoggerInterface::class, $logger);
    app()->forgetInstance(DurableSwarmManager::class);
}

/**
 * Whether the branch-failure hook fired: a race set up inside it proves nothing if
 * the log line it keys on ever moves, so every test using it asserts this.
 */
function joinWaitBranchFailureHookRan(bool $ran = false, bool $reset = false): bool
{
    static $fired = false;

    if ($reset) {
        $fired = false;
    }

    if ($ran) {
        $fired = true;
    }

    return $fired;
}

function joinWaitFailingPlan(): array
{
    return [
        'start_at' => 'parallel_node',
        'nodes' => [
            'parallel_node' => ['type' => 'parallel', 'branches' => ['writer_node', 'failing_node'], 'next' => 'finish_node'],
            'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
            'failing_node' => ['type' => 'worker', 'agent' => FailingPromptAgent::class, 'prompt' => 'failing-branch'],
            'finish_node' => ['type' => 'finish', 'output_from' => 'writer_node'],
        ],
    ];
}

/**
 * Start a fail_run run at its join and complete every branch but the failing one.
 * Returns [runId, failing branch id].
 *
 * @return array{0: string, 1: string}
 */
function joinWaitFailRunAtJoin(string $profile, bool $openWait): array
{
    $manager = app(DurableSwarmManager::class);
    $failing = FailingPromptAgent::class;

    if ($profile === 'qh') {
        $runId = 'join-wait-fail-'.Str::random(6);
        $context = RunContext::from('queued-hierarchical-task', $runId);
        (new InvokeSwarm(FakeHierarchicalParallelFailBranchSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));
    } else {
        $runId = FakeParallelFailingSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    }

    expect($manager->find($runId)['status'])->toBe('waiting');

    if ($openWait) {
        $manager->wait($runId, 'approval');
    }

    $failingBranchId = null;

    foreach (app(DurableRunStore::class)->branchesFor($runId, $manager->find($runId)['current_node_id']) as $branch) {
        if ($branch['agent_class'] === $failing) {
            $failingBranchId = (string) $branch['branch_id'];

            continue;
        }

        (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle($manager);
    }

    return [$runId, (string) $failingBranchId];
}

function joinWaitAssertFailedNotJoined(string $runId, array $before): void
{
    $manager = app(DurableSwarmManager::class);

    expect($manager->find($runId)['status'])->toBe('failed')
        ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('failed')
        ->and(joinWaitDispatches($runId))->toBe($before);
}

describe('fail_run ownership', function () {
    beforeEach(function () {
        joinWaitRuntime(queuedHierarchical: true);
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        config()->set('swarm.durable.parallel.failure_policy', 'fail_run');
        FakeHierarchicalCoordinator::fake([joinWaitFailingPlan()]);
        FakeResearcher::fake(['research-out']);
        FakeWriter::fake(['writer-out']);
        Event::fake([SwarmFailed::class]);
    });

    test('a fail_run branch failure fails the parent at once even with an open wait', function (string $profile) {
        [$runId, $failingBranchId] = joinWaitFailRunAtJoin($profile, openWait: true);
        $before = joinWaitDispatches($runId);

        (new AdvanceDurableBranch($runId, $failingBranchId))->handle(app(DurableSwarmManager::class));

        joinWaitAssertFailedNotJoined($runId, $before);
    })->with(['step-durable parallel' => 'sd', 'queued-hierarchical' => 'qh']);

    test('a signal closing the wait between the branch failure and the parent failure does not release the join', function (string $profile) {
        $runId = null;
        joinWaitBranchFailureLogger(function () use (&$runId): void {
            app(DurableSwarmManager::class)->signal($runId, 'approval');

            expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('waiting');
        });
        [$runId, $failingBranchId] = joinWaitFailRunAtJoin($profile, openWait: true);
        $before = joinWaitDispatches($runId);

        (new AdvanceDurableBranch($runId, $failingBranchId))->handle(app(DurableSwarmManager::class));

        joinWaitAssertFailedNotJoined($runId, $before);
        expect(joinWaitBranchFailureHookRan())->toBeTrue();
    })->with(['step-durable parallel' => 'sd', 'queued-hierarchical' => 'qh']);

    test('a sibling finishing between the branch failure and the parent failure does not release the join', function () {
        $manager = app(DurableSwarmManager::class);
        $runId = FakeParallelFailingSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
        $branches = app(DurableRunStore::class)->branchesFor($runId, 'parallel');
        $failing = collect($branches)->firstWhere('agent_class', FailingPromptAgent::class);
        $siblings = collect($branches)->reject(fn (array $b): bool => $b['branch_id'] === $failing['branch_id'])->values();

        (new AdvanceDurableBranch($runId, $siblings[0]['branch_id']))->handle($manager);
        joinWaitBranchFailureLogger(function () use ($runId, $siblings): void {
            (new AdvanceDurableBranch($runId, $siblings[1]['branch_id']))->handle(app(DurableSwarmManager::class));
        });
        $before = joinWaitDispatches($runId);

        (new AdvanceDurableBranch($runId, $failing['branch_id']))->handle(app(DurableSwarmManager::class));

        joinWaitAssertFailedNotJoined($runId, $before);
        expect(joinWaitBranchFailureHookRan())->toBeTrue();
    });

    test('a failing worker that never reaches the parent leaves it to swarm:recover, which fails it', function (string $profile, bool $openWait) {
        joinWaitBranchFailureLogger(throw: true);
        [$runId, $failingBranchId] = joinWaitFailRunAtJoin($profile, openWait: $openWait);
        $manager = app(DurableSwarmManager::class);
        $before = joinWaitDispatches($runId);

        expect(fn () => (new AdvanceDurableBranch($runId, $failingBranchId))->handle($manager))->toThrow(RuntimeException::class, 'logging unavailable');
        expect(joinWaitBranchFailureHookRan())->toBeTrue()
            ->and($manager->find($runId)['status'])->toBe('waiting');

        if ($openWait) {
            joinWaitAgeRun($runId);
            Artisan::call('swarm:recover');
            expect($manager->find($runId)['status'])->toBe('waiting');

            // Closing the wait does not release the join either: the failure is owed.
            $manager->signal($runId, 'approval');
            expect($manager->find($runId)['status'])->toBe('waiting');
        }

        joinWaitAgeRun($runId);
        Artisan::call('swarm:recover');

        joinWaitAssertFailedNotJoined($runId, $before);
        Event::assertDispatchedTimes(SwarmFailed::class, 1);
    })->with(['step-durable parallel' => 'sd', 'queued-hierarchical' => 'qh'])->with(['no wait' => false, 'open wait' => true]);

    test('swarm:recover racing a live failing worker fails the parent once, never joins it', function (string $profile) {
        $runId = null;
        joinWaitBranchFailureLogger(function () use (&$runId): void {
            joinWaitAgeRun($runId);
            Artisan::call('swarm:recover');

            expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('failed');
        });
        [$runId, $failingBranchId] = joinWaitFailRunAtJoin($profile, openWait: false);
        $before = joinWaitDispatches($runId);

        (new AdvanceDurableBranch($runId, $failingBranchId))->handle(app(DurableSwarmManager::class));

        joinWaitAssertFailedNotJoined($runId, $before);
        expect(joinWaitBranchFailureHookRan())->toBeTrue();
        Event::assertDispatchedTimes(SwarmFailed::class, 1);
    })->with(['step-durable parallel' => 'sd', 'queued-hierarchical' => 'qh']);
});

describe('partial_success with every branch failed', function () {
    beforeEach(function () {
        joinWaitRuntime();
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        config()->set('swarm.durable.parallel.failure_policy', 'partial_success');
        FakeResearcher::fake(['research-out']);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
    });

    test('the join is still released and fails the run', function () {
        $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
        $manager = app(DurableSwarmManager::class);
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
        $manager->pause($runId);
        DB::table('swarm_durable_branches')->where('run_id', $runId)->update([
            'status' => 'failed',
            'failure' => json_encode(['message' => 'boom']),
            'finished_at' => now('UTC'),
            'execution_token' => null,
            'leased_until' => null,
        ]);
        $before = joinWaitDispatches($runId);

        $manager->resume($runId);

        expect($manager->find($runId)['status'])->toBe('pending')
            ->and(joinWaitDispatches($runId)['step'])->toBe($before['step'] + 1);

        (new AdvanceDurableSwarm($runId, 3))->handle($manager);

        expect($manager->find($runId)['status'])->toBe('failed');
    });
});

describe('wait timeouts and child outcomes at a join', function () {
    beforeEach(function () {
        joinWaitRuntime(queuedHierarchical: true);
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        FakeHierarchicalCoordinator::fake([joinWaitHierarchicalPlan()]);
        FakeResearcher::fake(['research-out', 'child-research']);
        FakeWriter::fake(['writer-out', 'child-writer']);
        FakeEditor::fake(['editor-out', 'child-editor']);
    });

    test('a timed-out wait at a join releases it through the profile\'s own continuation', function (string $profile) {
        $manager = app(DurableSwarmManager::class);

        if ($profile === 'qh') {
            $runId = 'join-wait-timeout-qh';
            joinWaitQueuedRun($runId, completeBranches: false);
            DB::table('swarm_durable_waits')->where('run_id', $runId)->delete();
            DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['wait_reason' => null, 'waiting_since' => null]);
            $node = 'parallel_node';
        } else {
            $runId = joinWaitParallelRun(completeBranches: false);
            DB::table('swarm_durable_waits')->where('run_id', $runId)->delete();
            $node = 'parallel';
        }

        $manager->wait($runId, 'approval', timeoutSeconds: 60);

        foreach (app(DurableRunStore::class)->branchesFor($runId, $node) as $branch) {
            (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle($manager);
        }

        $before = joinWaitDispatches($runId);
        expect($manager->find($runId)['status'])->toBe('waiting');

        $this->travel(2)->minutes();
        Artisan::call('swarm:recover');

        $context = app(ContextStore::class)->find($runId);
        expect($context['metadata']['durable_wait_outcomes']['approval']['status'])->toBe('timed_out')
            ->and($manager->find($runId)['status'])->toBe('pending');

        if ($profile === 'qh') {
            expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
                ->and(joinWaitDispatches($runId))->toBe(['step' => 0, 'queued_resume' => 1]);
            joinWaitResumeQueued($runId);
            expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
        } else {
            expect(joinWaitDispatches($runId)['step'])->toBe($before['step'] + 1);
            (new AdvanceDurableSwarm($runId, 3))->handle($manager);
            expect($manager->find($runId)['status'])->toBe('completed');
        }
    })->with(['step-durable parallel' => 'sd', 'queued-hierarchical' => 'qh']);

    test('a child finishing while the branches still run leaves the parent waiting for them', function (string $profile, bool $branchesFirst) {
        Event::fake([SwarmChildCompleted::class]);
        $manager = app(DurableSwarmManager::class);

        if ($profile === 'qh') {
            $runId = 'join-wait-child-qh-'.($branchesFirst ? 'a' : 'b');
            joinWaitQueuedRun($runId, completeBranches: false);
            DB::table('swarm_durable_waits')->where('run_id', $runId)->delete();
            $node = 'parallel_node';
        } else {
            $runId = joinWaitParallelRun(completeBranches: false);
            DB::table('swarm_durable_waits')->where('run_id', $runId)->delete();
            $node = 'parallel';
        }

        $childRunId = $manager->dispatchChildSwarm($runId, FakeSequentialSwarm::class, 'child-task')->childRunId;
        $completeBranches = function () use ($manager, $runId, $node): void {
            foreach (app(DurableRunStore::class)->branchesFor($runId, $node) as $branch) {
                (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle($manager);
            }
        };

        if ($branchesFirst) {
            $completeBranches();
            expect($manager->find($runId)['status'])->toBe('waiting');
        }

        $before = joinWaitDispatches($runId);

        foreach ([0, 1, 2] as $step) {
            (new AdvanceDurableSwarm($childRunId, $step))->handle($manager);
        }

        expect($manager->find($childRunId)['status'])->toBe('completed');

        // Reconciling the parent again (a duplicate delivery, or swarm:recover) neither
        // re-dispatches the child's terminal event nor writes a second continuation.
        $afterChild = joinWaitDispatches($runId);
        app(DurableChildSwarmCoordinator::class)->reconcileTerminalChildrenForParent($manager->find($runId));
        expect(joinWaitDispatches($runId))->toBe($afterChild);
        Event::assertDispatchedTimes(SwarmChildCompleted::class, 1);

        if (! $branchesFirst) {
            expect($manager->find($runId)['status'])->toBe('waiting')
                ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
                ->and(joinWaitDispatches($runId))->toBe($before);

            $completeBranches();
        }

        expect($manager->find($runId)['status'])->toBe('pending');

        if ($profile === 'qh') {
            expect(joinWaitDispatches($runId))->toBe(['step' => 0, 'queued_resume' => 1]);
            joinWaitResumeQueued($runId);
            expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
        } else {
            expect(joinWaitDispatches($runId)['step'])->toBe($before['step'] + 1);
            (new AdvanceDurableSwarm($runId, 3))->handle($manager);
            expect($manager->find($runId)['status'])->toBe('completed');
        }
    })->with(['step-durable parallel' => 'sd', 'queued-hierarchical' => 'qh'])->with(['branches still running' => false, 'branches already terminal' => true]);
});

describe('failure boundaries', function () {
    beforeEach(function () {
        joinWaitRuntime();
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        FakeResearcher::fake(['research-out']);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
    });

    test('a join release that fails after the wait closed rolls back, keeps the signal, and is left to swarm:recover', function () {
        Exceptions::fake();
        $failJoinDispatch = false;
        $realOutbox = app(DurableOutbox::class);
        $outbox = Mockery::mock($realOutbox);
        $outbox->shouldReceive('enqueueStep')->andReturnUsing(function (...$args) use (&$failJoinDispatch, $realOutbox): int {
            if ($failJoinDispatch) {
                throw new RuntimeException('outbox unavailable');
            }

            return $realOutbox->enqueueStep(...$args);
        });
        app()->instance(DurableOutbox::class, $outbox);
        app()->forgetInstance(DurableSwarmManager::class);

        $runId = joinWaitParallelRun();
        $manager = app(DurableSwarmManager::class);
        $before = joinWaitDispatches($runId);
        $failJoinDispatch = true;
        $laterCallbackRan = false;

        $signal = DB::transaction(function () use ($manager, $runId, &$laterCallbackRan) {
            $signal = $manager->signal($runId, 'approval', ['approved' => true]);
            DB::afterCommit(function () use (&$laterCallbackRan): void {
                $laterCallbackRan = true;
            });

            return $signal;
        });

        expect($signal->accepted)->toBeTrue()
            ->and($laterCallbackRan)->toBeTrue()
            ->and($manager->inspect($runId)->waits[0]['status'])->toBe('signalled')
            ->and(app(ContextStore::class)->find($runId)['metadata']['durable_signals']['approval']['payload'])->toBe(['approved' => true])
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
            ->and($manager->find($runId)['status'])->toBe('waiting')
            ->and(joinWaitDispatches($runId))->toBe($before);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'could not be released to its join'));

        $failJoinDispatch = false;
        joinWaitAgeRun($runId);
        Artisan::call('swarm:recover');

        expect($manager->find($runId)['status'])->toBe('pending');

        (new AdvanceDurableSwarm($runId, 3))->handle($manager);

        expect($manager->find($runId)['status'])->toBe('completed');
    });

    test('a timed-out wait whose outcome cannot be published is not left closed, and does not stop the sweep', function () {
        $failPublishFor = null;
        $realContextStore = app(ContextStore::class);
        $contextStore = Mockery::mock($realContextStore);
        $contextStore->shouldReceive('put')->andReturnUsing(function (RunContext $context, int $ttl) use (&$failPublishFor, $realContextStore): void {
            if ($context->runId === $failPublishFor) {
                throw new RuntimeException('context store unavailable');
            }

            $realContextStore->put($context, $ttl);
        });
        app()->instance(ContextStore::class, $contextStore);
        app()->forgetInstance(DurableSwarmManager::class);
        $manager = app(DurableSwarmManager::class);

        // Two runs at their join, each with a wait that times out in the same sweep; the
        // first one's outcome cannot be published.
        $broken = joinWaitParallelRun();
        $healthy = joinWaitParallelRun();

        foreach ([$broken, $healthy] as $runId) {
            DB::table('swarm_durable_waits')->where('run_id', $runId)->delete();
            $manager->wait($runId, 'approval', timeoutSeconds: 60);
        }

        $this->travel(2)->minutes();
        $failPublishFor = $broken;

        $manager->recover();

        expect($manager->inspect($broken)->waits[0]['status'])->toBe('waiting')
            ->and($manager->find($broken)['status'])->toBe('waiting')
            ->and($manager->inspect($healthy)->waits[0]['status'])->toBe('timed_out')
            ->and($manager->find($healthy)['status'])->toBe('pending');
    });

    test('every wait closer shares the factory\'s one join release', function () {
        $manager = app(DurableSwarmManager::class);
        $read = fn (object $object, string $property): mixed => (new ReflectionProperty($object, $property))->getValue($object);

        $hierarchical = $read($manager, 'hierarchicalCoordinator');
        $joinRelease = $read($hierarchical, 'joinRelease');

        expect($joinRelease)->toBeInstanceOf(DurableJoinRelease::class)
            ->and($read($read($manager, 'signalHandler'), 'joinRelease'))->toBe($joinRelease)
            ->and($read($read($manager, 'children'), 'joinRelease'))->toBe($joinRelease)
            ->and($read($read($manager, 'recovery'), 'joinRelease'))->toBe($joinRelease)
            ->and($read($joinRelease, 'runs'))->toBe($read($read($manager, 'signalHandler'), 'runs'));
    });
});

/**
 * Every AdvanceDurableSwarm pushed onto the fake queue for the run.
 *
 * @return array<int, int> the step index of each push
 */
function joinWaitPushedSteps(string $runId): array
{
    $steps = [];

    foreach (Queue::pushedJobs() as $pushes) {
        foreach ($pushes as $push) {
            if ($push['job'] instanceof AdvanceDurableSwarm && $push['job']->runId === $runId) {
                $steps[] = $push['job']->stepIndex;
            }
        }
    }

    return $steps;
}

describe('recovery and dispatch boundaries', function () {
    beforeEach(function () {
        joinWaitRuntime();
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        FakeResearcher::fake(['research-out']);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
    });

    test('one waiting join whose context cannot be read does not stop the rest of swarm:recover', function () {
        $manager = app(DurableSwarmManager::class);

        // The older candidate: a failed branch (so recovery reads its context) and no
        // context row left to read.
        $broken = joinWaitParallelRun(completeBranches: false);
        DB::table('swarm_durable_waits')->where('run_id', $broken)->delete();
        joinWaitMarkBranchesCompleted($broken);
        DB::table('swarm_durable_branches')->where('run_id', $broken)->where('branch_id', 'parallel:0')->update(['status' => 'failed', 'failure' => json_encode(['message' => 'boom'])]);
        DB::table('swarm_contexts')->where('run_id', $broken)->delete();
        DB::table('swarm_durable_runs')->where('run_id', $broken)->update(['updated_at' => now('UTC')->subMinutes(30)]);

        // A younger, healthy candidate in the same sweep.
        $healthy = joinWaitParallelRun(completeBranches: false);
        DB::table('swarm_durable_waits')->where('run_id', $healthy)->delete();
        joinWaitMarkBranchesCompleted($healthy);
        joinWaitAgeRun($healthy);

        $recovered = $manager->recover();

        expect($manager->find($healthy)['status'])->toBe('pending')
            ->and($manager->find($broken)['status'])->toBe('waiting')
            ->and($recovered)->toContain($healthy)
            ->and($recovered)->not->toContain($broken);
    });

    test('a parent swarm:recover fails for an unsettled fail_run failure is not reported as redispatched', function () {
        config()->set('swarm.durable.parallel.failure_policy', 'fail_run');
        $manager = app(DurableSwarmManager::class);
        $runId = joinWaitParallelRun(completeBranches: false);
        DB::table('swarm_durable_waits')->where('run_id', $runId)->delete();
        joinWaitMarkBranchesCompleted($runId);
        DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', 'parallel:0')->update(['status' => 'failed', 'failure' => json_encode(['message' => 'boom'])]);
        joinWaitAgeRun($runId);
        $before = joinWaitDispatches($runId);

        $recovered = $manager->recover();

        expect($manager->find($runId)['status'])->toBe('failed')
            ->and($recovered)->not->toContain($runId)
            ->and(joinWaitDispatches($runId))->toBe($before);
    });

    test('with eager dispatch on, the join released after a signal is pushed once — bare or inside an application transaction', function (bool $inTransaction) {
        config()->set('swarm.durable.eager_dispatch', true);
        Queue::fake();
        $manager = app(DurableSwarmManager::class);
        $runId = joinWaitParallelRun();
        $before = joinWaitDispatches($runId);

        expect(joinWaitPushedSteps($runId))->not->toContain(3);

        $signal = fn () => $manager->signal($runId, 'approval');
        $inTransaction ? DB::transaction($signal) : $signal();

        expect($manager->find($runId)['status'])->toBe('pending')
            ->and(array_values(array_filter(joinWaitPushedSteps($runId), fn (int $step): bool => $step === 3)))->toBe([3])
            ->and(joinWaitDispatches($runId)['step'])->toBe($before['step'] + 1);
    })->with(['bare' => false, 'inside an application transaction' => true]);

    test('the fail_run release fails the parent at once with an open wait only for a store with the ReleasesWaitingRunForFailure capability', function (string $store) {
        config()->set('swarm.durable.parallel.failure_policy', 'fail_run');
        $manager = app(DurableSwarmManager::class);
        $runId = FakeParallelFailingSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
        $manager->wait($runId, 'approval');
        joinWaitMarkBranchesCompleted($runId);
        DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', 'parallel:1')->update(['status' => 'failed', 'failure' => json_encode(['message' => 'boom'])]);

        // The durable manager itself requires the bundled store (DurableRunInspector), so
        // a store other than the bundled one is reached only by a coordinator built over it.
        $durableRuns = match ($store) {
            'bundled' => app(DurableRunStore::class),
            'decorating with the capability' => new JoinWaitFailureReleasingDurableRunStore(app(DurableRunStore::class)),
            'decorating without the capability' => new DecoratingDurableRunStore(app(DurableRunStore::class)),
        };
        $coordinator = app()->makeWith(DurableHierarchicalCoordinator::class, ['durableRuns' => $durableRuns]);
        $failed = false;

        $coordinator->failWaitingParentFromBranches(
            $durableRuns->find($runId),
            app(DurableRunContext::class)->loadContext($runId),
            300,
            function () use (&$failed): void {
                $failed = true;
            },
        );

        // A store with the capability fails the parent at once despite the open wait; one
        // without it goes through releaseWaitingRunForJoin(), which refuses while the wait
        // is open.
        $capable = $store !== 'decorating without the capability';
        expect($durableRuns instanceof ReleasesWaitingRunForFailure)->toBe($capable)
            ->and($failed)->toBe($capable)
            ->and($durableRuns->find($runId)['status'])->toBe($capable ? 'pending' : 'waiting');
    })->with(['bundled', 'decorating with the capability', 'decorating without the capability']);
});

/**
 * A decorating store that keeps the ReleasesWaitingRunForFailure capability by
 * implementing it and forwarding the call to the store it wraps.
 */
final class JoinWaitFailureReleasingDurableRunStore extends DecoratingDurableRunStore implements ReleasesWaitingRunForFailure
{
    public function __construct(private readonly DurableRunStore&ReleasesWaitingRunForFailure $capable)
    {
        parent::__construct($capable);
    }

    public function releaseWaitingRunForFailure(string $runId, int $nextStepIndex): bool
    {
        return $this->capable->releaseWaitingRunForFailure($runId, $nextStepIndex);
    }
}

final class JoinRecoveryQueue extends NullQueue
{
    public static bool $fail = false;

    public function push($job, $data = '', $queue = null)
    {
        if (self::$fail) {
            throw new RuntimeException('recovery queue unavailable');
        }

        return parent::push($job, $data, $queue);
    }
}

function joinRecoveryThrowingQueue(): void
{
    JoinRecoveryQueue::$fail = false;
    config()->set('queue.connections.durable-test', ['driver' => 'join-recovery']);
    app('queue')->extend('join-recovery', fn (): ConnectorInterface => new class implements ConnectorInterface
    {
        public function connect(array $config)
        {
            return new JoinRecoveryQueue;
        }
    });
    expect(Queue::connection('durable-test'))->toBeInstanceOf(JoinRecoveryQueue::class);
}

/**
 * Remove the fixture wait without a closer so recovery alone can release the join.
 */
function joinRecoveryCandidate(string $profile): string
{
    if ($profile === 'qh') {
        $runId = 'join-recovery-'.Str::uuid();
        joinWaitQueuedRun($runId, completeBranches: false);
    } elseif ($profile === 'hierarchical') {
        $runId = FakeHierarchicalFullSwarm::make()->dispatchDurable('hierarchical-task')->runId;
        $manager = app(DurableSwarmManager::class);
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
        (new AdvanceDurableSwarm($runId, 1))->handle($manager);
        $manager->wait($runId, 'approval');
    } else {
        $runId = joinWaitParallelRun(completeBranches: false);
    }

    joinWaitMarkBranchesCompleted($runId);
    DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', 'approval')->delete();
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update([
        'queue_connection' => 'durable-test',
        'queue_name' => 'recovered-joins',
    ]);
    joinWaitAgeRun($runId);
    expect(array_column(app(DurableRunStore::class)->recoverableWaitingJoins(runId: $runId), 'run_id'))->toBe([$runId]);

    return $runId;
}

function joinRecoveryRows(string $runId, int $baseline): Builder
{
    $run = app(DurableRunStore::class)->find($runId);

    return DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('id', '>', $baseline)
        ->where('dispatch_type', $run['coordination_profile'] === 'queue_hierarchical_parallel' ? 'queued_resume' : 'step');
}

/** @return list<AdvanceDurableSwarm|ResumeQueuedHierarchicalSwarm> */
function joinRecoveryJobs(string $runId, int $step): array
{
    $jobs = [];

    foreach (Queue::pushedJobs() as $pushes) {
        foreach ($pushes as $push) {
            $job = $push['job'];
            if (($job instanceof AdvanceDurableSwarm && $job->stepIndex === $step || $job instanceof ResumeQueuedHierarchicalSwarm) && $job->runId === $runId) {
                $jobs[] = $job;
            }
        }
    }

    return $jobs;
}

function joinRecoveryPushedJob(string $profile, string $runId, int $step): AdvanceDurableSwarm|ResumeQueuedHierarchicalSwarm
{
    $jobs = joinRecoveryJobs($runId, $step);
    expect($jobs)->toHaveCount(1);
    $job = $jobs[0];
    expect($job)->toBeInstanceOf($profile === 'qh' ? ResumeQueuedHierarchicalSwarm::class : AdvanceDurableSwarm::class)
        ->and($job->runId)->toBe($runId)
        ->and($job->connection)->toBe('durable-test')
        ->and($job->queue)->toBe('recovered-joins');

    return $job;
}

describe('swarm:recover releases a join through the outbox', function () {
    beforeEach(function () {
        JoinRecoveryQueue::$fail = false;
        joinWaitRuntime(queuedHierarchical: true);
        config()->set('swarm.durable.eager_dispatch', false);
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        FakeHierarchicalCoordinator::fake([joinWaitHierarchicalPlan()]);
        FakeResearcher::fake(['research-out']);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
    });

    test('recovery writes one continuation outbox row and pushes nothing itself; the relay delivers the job that completes the run', function (string $profile) {
        $runId = joinRecoveryCandidate($profile);
        $manager = app(DurableSwarmManager::class);
        $step = (int) $manager->find($runId)['next_step_index'];
        $baseline = (int) DB::table('swarm_durable_outbox')->max('id');
        Queue::fake();

        $recovered = $manager->recover();

        expect(joinRecoveryRows($runId, $baseline)->count())->toBe(1)
            ->and($manager->find($runId)['status'])->toBe('pending')
            ->and($recovered)->toContain($runId)
            ->and(joinRecoveryJobs($runId, $step))->toBe([]);
        $row = joinRecoveryRows($runId, $baseline)->first();
        expect($row->queue_connection)->toBe('durable-test')->and($row->queue_name)->toBe('recovered-joins');
        if ($profile !== 'qh') {
            expect((int) json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)['step_index'])->toBe($step);
        }

        Artisan::call('swarm:relay');
        $job = joinRecoveryPushedJob($profile, $runId, $step);
        $job->handle(app($profile === 'qh' ? QueuedHierarchicalCoordinator::class : DurableSwarmManager::class));

        expect($manager->find($runId)['status'])->toBe('completed')
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
    })->with(['parallel', 'hierarchical', 'qh']);

    test('when the queue is down, the recovered intent survives the failed relay push and is delivered once its reservation goes stale', function (string $profile) {
        Exceptions::fake();
        joinRecoveryThrowingQueue();
        $runId = joinRecoveryCandidate($profile);
        $manager = app(DurableSwarmManager::class);
        $step = (int) $manager->find($runId)['next_step_index'];
        $baseline = (int) DB::table('swarm_durable_outbox')->max('id');
        JoinRecoveryQueue::$fail = true;

        $manager->recover();
        expect(joinRecoveryRows($runId, $baseline)->count())->toBe(1)
            ->and($manager->find($runId)['status'])->toBe('pending');
        $result = app(DurableOutbox::class)->drain();
        expect($result->failed)->toBeGreaterThan(0)
            ->and(joinRecoveryRows($runId, $baseline)->count())->toBe(1)
            ->and(joinRecoveryRows($runId, $baseline)->value('reserved_at'))->not->toBeNull();

        JoinRecoveryQueue::$fail = false;
        Queue::fake();
        expect(app(DurableOutbox::class)->drain()->claimed)->toBe(0);
        $this->travel((int) config('swarm.durable.relay.reservation_timeout_seconds') + 1)->seconds();
        Artisan::call('swarm:relay');
        joinRecoveryPushedJob($profile, $runId, $step);
        expect(joinRecoveryRows($runId, $baseline)->count())->toBe(0);
    })->with(['parallel', 'hierarchical', 'qh']);

    test('an outbox insert failure rolls back only its candidate and later phases still run', function (string $profile) {
        $broken = null;
        $realOutbox = app(DurableOutbox::class);
        $outbox = Mockery::mock($realOutbox);

        foreach (['enqueueStep', 'enqueueQueuedResume'] as $method) {
            $outbox->shouldReceive($method)->andReturnUsing(function (...$args) use (&$broken, $realOutbox, $method) {
                if ($args[0] === $broken) {
                    throw new RuntimeException('recovery outbox unavailable');
                }

                return $realOutbox->{$method}(...$args);
            });
        }

        app()->instance(DurableOutbox::class, $outbox);
        // Two queued-hierarchical candidates each ask the coordinator for a plan.
        FakeHierarchicalCoordinator::fake([joinWaitHierarchicalPlan(), joinWaitHierarchicalPlan()]);
        $broken = joinRecoveryCandidate($profile);
        DB::table('swarm_durable_runs')->where('run_id', $broken)->update(['updated_at' => now('UTC')->subMinutes(30)]);
        $healthy = joinRecoveryCandidate($profile);
        $timeout = joinWaitParallelRun(completeBranches: false);
        $manager = app(DurableSwarmManager::class);
        DB::table('swarm_durable_waits')->where('run_id', $timeout)->update(['timeout_at' => now('UTC')->subMinute()]);
        $baseline = (int) DB::table('swarm_durable_outbox')->max('id');
        Queue::fake();

        $recovered = $manager->recover();

        expect($manager->find($broken)['status'])->toBe('waiting')
            ->and(joinRecoveryRows($broken, $baseline)->count())->toBe(0)
            ->and($recovered)->not->toContain($broken)
            ->and($manager->find($healthy)['status'])->toBe('pending')
            ->and(joinRecoveryRows($healthy, $baseline)->count())->toBe(1)
            ->and($recovered)->toContain($healthy)
            ->and($manager->inspect($timeout)->waits[0]['status'])->toBe('timed_out')
            ->and(app(ContextStore::class)->find($timeout)['metadata']['durable_wait_outcomes']['approval']['status'])->toBe('timed_out');
    })->with(['parallel', 'qh']);

    test('a recovery marker that fails after the release committed still reports the run as recovered and leaves its continuation to the relay', function (string $profile) {
        $logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $warnings = [];
        $logger->shouldReceive('warning')->andReturnUsing(function (string|Stringable $message, array $context = []) use (&$warnings): void {
            $warnings[] = ['message' => (string) $message, 'run_id' => $context['run_id'] ?? null];
        });
        app()->instance(LoggerInterface::class, $logger);
        $store = app(MarkerFailingDurableRunStore::class);
        app()->instance(DurableRunStore::class, $store);
        $runId = joinRecoveryCandidate($profile);
        $manager = app(DurableSwarmManager::class);
        $step = (int) $manager->find($runId)['next_step_index'];
        $baseline = (int) DB::table('swarm_durable_outbox')->max('id');
        $store->failMarkerFor = $runId;
        Queue::fake();

        $recovered = $manager->recover();

        $mine = array_values(array_filter($warnings, fn (array $w): bool => $w['run_id'] === $runId));
        expect($manager->find($runId)['status'])->toBe('pending')
            ->and((int) ($manager->find($runId)['recovery_count'] ?? 0))->toBe(0)
            ->and(joinRecoveryRows($runId, $baseline)->count())->toBe(1)
            ->and($recovered)->toContain($runId)
            ->and($mine)->toHaveCount(1)
            ->and($mine[0]['message'])->toContain('could not record the recovery')
            ->and($mine[0]['message'])->not->toContain('retried on the next sweep');

        Artisan::call('swarm:relay');
        joinRecoveryPushedJob($profile, $runId, $step);
    })->with(['parallel', 'qh']);

    test('eager recovery pushes once only after the outer commit with its outbox id', function (string $profile) {
        $runId = joinRecoveryCandidate($profile);
        $manager = app(DurableSwarmManager::class);
        $step = (int) $manager->find($runId)['next_step_index'];
        $baseline = (int) DB::table('swarm_durable_outbox')->max('id');
        config()->set('swarm.durable.eager_dispatch', true);
        Queue::fake();

        DB::transaction(function () use ($manager, $runId, $step, $baseline) {
            $manager->recover();
            expect(joinRecoveryJobs($runId, $step))->toBe([])
                ->and(joinRecoveryRows($runId, $baseline)->count())->toBe(1);
        });

        $job = joinRecoveryPushedJob($profile, $runId, $step);
        expect($job->outboxEntryId)->toBe((int) joinRecoveryRows($runId, $baseline)->value('id'))
            // Reserved for the eager push, so the relay cannot claim it a second time.
            ->and(joinRecoveryRows($runId, $baseline)->value('reserved_at'))->not->toBeNull();
        app(DurableOutbox::class)->drain();
        expect(joinRecoveryJobs($runId, $step))->toHaveCount(1);
    })->with(['parallel', 'hierarchical']);

    test('rolling back eager recovery restores the waiting join and drops its intent and push', function (string $profile) {
        $runId = joinRecoveryCandidate($profile);
        $manager = app(DurableSwarmManager::class);
        $step = (int) $manager->find($runId)['next_step_index'];
        $baseline = (int) DB::table('swarm_durable_outbox')->max('id');
        config()->set('swarm.durable.eager_dispatch', true);
        Queue::fake();
        DB::beginTransaction();
        try {
            $manager->recover();
            expect(joinRecoveryRows($runId, $baseline)->count())->toBe(1)
                ->and(joinRecoveryJobs($runId, $step))->toBe([]);
        } finally {
            DB::rollBack();
        }

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and(joinRecoveryRows($runId, $baseline)->count())->toBe(0)
            ->and(joinRecoveryJobs($runId, $step))->toBe([]);
    })->with(['parallel', 'hierarchical']);

    test('a failed eager push leaves a reserved intent for the relay after its timeout', function (string $profile) {
        Exceptions::fake();
        joinRecoveryThrowingQueue();
        $runId = joinRecoveryCandidate($profile);
        $manager = app(DurableSwarmManager::class);
        $step = (int) $manager->find($runId)['next_step_index'];
        $baseline = (int) DB::table('swarm_durable_outbox')->max('id');
        config()->set('swarm.durable.eager_dispatch', true);
        JoinRecoveryQueue::$fail = true;

        DB::transaction(fn () => $manager->recover());
        expect(joinRecoveryRows($runId, $baseline)->count())->toBe(1)
            ->and(joinRecoveryRows($runId, $baseline)->value('reserved_at'))->not->toBeNull();
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'Eager durable dispatch failed'));
        JoinRecoveryQueue::$fail = false;
        Queue::fake();
        app(DurableOutbox::class)->drain();
        expect(joinRecoveryJobs($runId, $step))->toBe([])
            ->and(joinRecoveryRows($runId, $baseline)->count())->toBe(1);
        $this->travel((int) config('swarm.durable.relay.reservation_timeout_seconds') + 1)->seconds();
        Artisan::call('swarm:relay');
        joinRecoveryPushedJob($profile, $runId, $step);
        expect(joinRecoveryRows($runId, $baseline)->count())->toBe(0);
    })->with(['parallel', 'hierarchical']);
});

/**
 * Bind a history store whose continuation-lease acquisition runs $hook first, once.
 */
function strayResumeHookAcquisition(Closure $hook): void
{
    $store = app(HookedContinuationRunHistoryStore::class);
    $store->beforeContinuationLease = $hook;
    app()->instance(RunHistoryStore::class, $store);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(QueuedHierarchicalCoordinator::class);
    app()->forgetInstance(DurableSwarmManager::class);
}

function strayResumeInvoke(string $runId): void
{
    $context = RunContext::from('queued-hierarchical-task', $runId);
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));
}

function strayResumeAdvanceBranches(string $runId, string $nodeId): void
{
    $manager = app(DurableSwarmManager::class);

    foreach (app(DurableRunStore::class)->branchesFor($runId, $nodeId) as $branch) {
        if (! in_array($branch['status'] ?? null, ['completed', 'failed', 'cancelled'], true)) {
            (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle($manager);
        }
    }
}

function strayResumeHistoryWaitingFlag(string $runId): bool
{
    return (bool) (app(RunHistoryStore::class)->find($runId)['metadata']['queue_hierarchical_waiting_parallel'] ?? false);
}

/**
 * A ResumeQueuedHierarchicalSwarm job runs the continuation only when the run's
 * durable row has been released to its join ('pending'). A stray or duplicate
 * delivery while the row is still 'waiting' — or paused, or on its next boundary —
 * changes nothing (#610).
 */
describe('stray queued-hierarchical resume (#610)', function () {
    beforeEach(function () {
        joinWaitRuntime(queuedHierarchical: true);
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        FakeHierarchicalCoordinator::fake([joinWaitHierarchicalPlan()]);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
        Event::fake([SwarmCompleted::class, SwarmFailed::class]);
    });

    test('a duplicate resume while an open wait holds the join changes nothing; the genuine resume completes the run once', function () {
        $runId = 'qh-stray-open-wait';
        joinWaitQueuedRun($runId);
        $manager = app(DurableSwarmManager::class);

        joinWaitResumeQueued($runId);

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
            ->and(strayResumeHistoryWaitingFlag($runId))->toBeTrue()
            ->and(joinWaitDispatches($runId)['queued_resume'])->toBe(0);
        Event::assertNotDispatched(SwarmCompleted::class);
        Event::assertNotDispatched(SwarmFailed::class);

        expect($manager->signal($runId, 'approval')->accepted)->toBeTrue()
            ->and($manager->find($runId)['status'])->toBe('pending')
            ->and(joinWaitDispatches($runId)['queued_resume'])->toBe(1);

        joinWaitResumeQueued($runId);
        joinWaitResumeQueued($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed')
            ->and(app(RunHistoryStore::class)->find($runId)['output'])->toBe('editor-out');
        Event::assertDispatchedTimes(SwarmCompleted::class, 1);
        Event::assertNotDispatched(SwarmFailed::class);
    });

    test('a duplicate resume while branches are still active does not fail the run; the run then completes normally', function () {
        $runId = 'qh-stray-active-branches';
        strayResumeInvoke($runId);
        $manager = app(DurableSwarmManager::class);

        joinWaitResumeQueued($runId);

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
            ->and(strayResumeHistoryWaitingFlag($runId))->toBeTrue();
        Event::assertNotDispatched(SwarmFailed::class);

        strayResumeAdvanceBranches($runId, 'parallel_node');

        expect($manager->find($runId)['status'])->toBe('pending')
            ->and(joinWaitDispatches($runId)['queued_resume'])->toBe(1);

        joinWaitResumeQueued($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
        Event::assertDispatchedTimes(SwarmCompleted::class, 1);
        Event::assertNotDispatched(SwarmFailed::class);
    });

    test('a crashed continuation is reclaimed only while its durable row is still released', function () {
        $runId = 'qh-stray-reclaim';
        strayResumeInvoke($runId);
        strayResumeAdvanceBranches($runId, 'parallel_node');

        /** @var ClaimsQueuedRunExecution $history */
        $history = app(RunHistoryStore::class);
        expect($history->acquireQueuedRunContinuationLease($runId, 3600, 300)->outcome)->toBe('fresh');

        // The worker died holding the lease; its lease has expired.
        DB::table('swarm_run_histories')->where('run_id', $runId)->update(['leased_until' => now('UTC')->subMinutes(10)]);
        $deadToken = DB::table('swarm_run_histories')->where('run_id', $runId)->value('execution_token');

        DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['status' => 'waiting']);
        joinWaitResumeQueued($runId);

        expect(DB::table('swarm_run_histories')->where('run_id', $runId)->value('status'))->toBe('running')
            ->and(DB::table('swarm_run_histories')->where('run_id', $runId)->value('execution_token'))->toBe($deadToken)
            ->and(Carbon::parse(DB::table('swarm_run_histories')->where('run_id', $runId)->value('leased_until'))->isPast())->toBeTrue()
            ->and(app(DurableRunStore::class)->recoverableQueuedResumes(runId: $runId))->toBe([]);
        Event::assertNotDispatched(SwarmCompleted::class);

        DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['status' => 'pending']);

        expect(array_column(app(DurableRunStore::class)->recoverableQueuedResumes(runId: $runId), 'run_id'))->toBe([$runId]);

        joinWaitResumeQueued($runId);

        expect($history->find($runId)['status'])->toBe('completed');
        Event::assertDispatchedTimes(SwarmCompleted::class, 1);
    });

    test('the continuation lease is never taken for a run that is not queued-hierarchical', function () {
        $runId = 'qh-stray-other-profile';
        strayResumeInvoke($runId);
        strayResumeAdvanceBranches($runId, 'parallel_node');

        /** @var ClaimsQueuedRunExecution $history */
        $history = app(RunHistoryStore::class);
        expect($history->acquireQueuedRunContinuationLease($runId, 3600, 300)->outcome)->toBe('fresh');
        DB::table('swarm_run_histories')->where('run_id', $runId)->update(['leased_until' => now('UTC')->subMinutes(10)]);
        $before = DB::table('swarm_run_histories')->where('run_id', $runId)->first();

        // Released and stranded like a crashed continuation, but on another profile.
        DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['coordination_profile' => 'step_durable']);

        expect($history->acquireQueuedRunContinuationLease($runId, 3600, 300)->outcome)->toBe('not_released');

        $after = DB::table('swarm_run_histories')->where('run_id', $runId)->first();
        expect($after->status)->toBe('running')
            ->and($after->execution_token)->toBe($before->execution_token)
            ->and($after->leased_until)->toBe($before->leased_until);
    });

    test('a resume delivered while the released run is paused changes nothing; resume() re-releases the join once', function () {
        $runId = 'qh-stray-paused';
        strayResumeInvoke($runId);
        strayResumeAdvanceBranches($runId, 'parallel_node');
        $manager = app(DurableSwarmManager::class);

        expect($manager->find($runId)['status'])->toBe('pending')
            ->and(joinWaitDispatches($runId)['queued_resume'])->toBe(1);

        $manager->pause($runId);
        joinWaitResumeQueued($runId);

        expect($manager->find($runId)['status'])->toBe('paused');
        Event::assertNotDispatched(SwarmCompleted::class);

        $manager->resume($runId);

        expect($manager->find($runId)['status'])->toBe('pending')
            ->and(joinWaitDispatches($runId)['queued_resume'])->toBe(2);

        joinWaitResumeQueued($runId);
        joinWaitResumeQueued($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
        Event::assertDispatchedTimes(SwarmCompleted::class, 1);
    });

    test('a duplicate that read the run before a signal released the join continues from the context the signal published', function () {
        $runId = 'qh-stray-stale-context';
        joinWaitQueuedRun($runId);

        // The duplicate has done its pre-lease reads; the signal commits its outcome and
        // releases the join before the duplicate asks for the lease.
        strayResumeHookAcquisition(function (string $runId): void {
            expect(app(DurableSwarmManager::class)->signal($runId, 'approval')->accepted)->toBeTrue();
        });

        joinWaitResumeQueued($runId);

        $context = app(ContextStore::class)->find($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed')
            ->and($context['metadata']['durable_wait_outcomes']['approval']['status'] ?? null)->toBe('signalled');
        Event::assertDispatchedTimes(SwarmCompleted::class, 1);
    });

    test('a duplicate that read the run at an earlier boundary continues from the boundary that is released now', function () {
        FakeHierarchicalCoordinator::fake([[
            'start_at' => 'first_parallel',
            'nodes' => [
                'first_parallel' => ['type' => 'parallel', 'branches' => ['writer_node', 'editor_node'], 'next' => 'second_parallel'],
                'second_parallel' => ['type' => 'parallel', 'branches' => ['research_node', 'second_editor_node'], 'next' => 'finish_node'],
                'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
                'editor_node' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor-branch'],
                'research_node' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'research-branch'],
                'second_editor_node' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'second-editor-branch'],
                'finish_node' => ['type' => 'finish', 'output_from' => 'research_node'],
            ],
        ]]);
        FakeEditor::fake(['editor-out', 'second-editor-out']);
        $researched = 0;
        FakeResearcher::fake(function () use (&$researched): string {
            $researched++;

            return 'research-out';
        });

        $runId = 'qh-stray-stale-boundary';
        strayResumeInvoke($runId);
        strayResumeAdvanceBranches($runId, 'first_parallel');

        expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('pending');

        // The duplicate read the run released at the first boundary. Before it asks for
        // the lease, the genuine resume runs that boundary's continuation to the second
        // fan-out, and the second boundary's branches finish and release it.
        strayResumeHookAcquisition(function (string $runId): void {
            joinWaitResumeQueued($runId);

            expect(app(DurableSwarmManager::class)->find($runId)['current_node_id'])->toBe('second_parallel')
                ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting');

            strayResumeAdvanceBranches($runId, 'second_parallel');
        });

        joinWaitResumeQueued($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed')
            ->and(app(RunHistoryStore::class)->find($runId)['output'])->toBe('research-out')
            ->and($researched)->toBe(1)
            ->and(DB::table('swarm_durable_branches')->where('run_id', $runId)->where('parent_node_id', 'second_parallel')->count())->toBe(2);
        Event::assertDispatchedTimes(SwarmCompleted::class, 1);
        Event::assertNotDispatched(SwarmFailed::class);
    });

    test('a read that fails after the lease is taken does not fail the run; swarm:recover redispatches it and the resume reclaims it', function () {
        $runId = 'qh-stray-hydration-failure';
        strayResumeInvoke($runId);
        strayResumeAdvanceBranches($runId, 'parallel_node');

        // The resume reads the context twice: an existence check before the lease, then
        // the read the continuation runs from, after it. Fail the second.
        $contextStore = app(FailingFindContextStore::class);
        $contextStore->failRunId = $runId;
        $contextStore->failOnCall = 2;
        app()->instance(ContextStore::class, $contextStore);
        app()->forgetInstance(SwarmRunner::class);
        app()->forgetInstance(QueuedHierarchicalCoordinator::class);
        app()->forgetInstance(DurableSwarmManager::class);

        expect(fn () => joinWaitResumeQueued($runId))->toThrow(RuntimeException::class, "Context read for [{$runId}] failed.");

        expect(DB::table('swarm_run_histories')->where('run_id', $runId)->value('status'))->toBe('running')
            ->and(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('pending');
        Event::assertNotDispatched(SwarmFailed::class);

        DB::table('swarm_run_histories')->where('run_id', $runId)->update(['leased_until' => now('UTC')->subMinutes(10)]);
        Queue::fake();

        Artisan::call('swarm:recover', ['--run-id' => $runId]);

        $resumes = Queue::pushed(ResumeQueuedHierarchicalSwarm::class, fn (ResumeQueuedHierarchicalSwarm $job): bool => $job->runId === $runId);
        expect($resumes)->toHaveCount(1);

        $resumes->first()->handle(app(QueuedHierarchicalCoordinator::class));

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
        Event::assertDispatchedTimes(SwarmCompleted::class, 1);
        Event::assertNotDispatched(SwarmFailed::class);
    });
});

/**
 * The run row as the closer's locking read sees it.
 */
function joinWaitRunRow(string $runId): object
{
    return DB::table('swarm_durable_runs')->where('run_id', $runId)->first(['status', 'current_node_id', 'current_step_index', 'next_step_index']);
}

/**
 * A hierarchical plan whose parallel node is named like the node a lease writes for
 * the step after its join (`step:3`): a plan may use any id.
 */
function joinWaitStepNamedPlan(): array
{
    $plan = joinWaitHierarchicalPlan();
    $plan['start_at'] = 'step:3';
    $plan['nodes']['step:3'] = $plan['nodes']['parallel_node'];
    unset($plan['nodes']['parallel_node']);

    return $plan;
}

/*
 * #617. Inside an application transaction that read before it closed a wait, MySQL's
 * REPEATABLE READ snapshot can be older than the run's fan-out, so the closer cannot
 * see the branch rows. It then decides from the locked run row, which is current.
 *
 * SQLite has no such snapshot. These tests remove the branch rows of a run the real
 * fan-out wrote to stand in for a closer that cannot see them. That pins the decision
 * to the row each fan-out path really leaves, and to the rows that only look like one.
 * It proves nothing about concurrency, nor about the join's later release (the rows it
 * would read are gone): the real-engine test for that is in
 * tests/ProcessConcurrency/DurableJoinOpenWaitTest.php.
 */
describe('a closer that cannot see the branch rows (#617)', function () {
    beforeEach(function () {
        Artisan::call('migrate:fresh', ['--database' => 'testing']);
        FakeResearcher::fake(['research-out']);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
    });

    test('the last wait closing on a fanned-out run leaves it waiting, whichever path fanned it out', function (string $profile) {
        joinWaitRuntime(queuedHierarchical: $profile === 'queued-hierarchical');
        $manager = app(DurableSwarmManager::class);

        if ($profile === 'top-level parallel') {
            $runId = joinWaitParallelRun(completeBranches: false);
        } elseif ($profile === 'queued-hierarchical') {
            FakeHierarchicalCoordinator::fake([joinWaitStepNamedPlan()]);
            $runId = 'join-wait-617-qh';
            $context = RunContext::from('queued-hierarchical-task', $runId);
            (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));
            $manager->wait($runId, 'approval');
        } else {
            FakeHierarchicalCoordinator::fake([joinWaitStepNamedPlan()]);
            $runId = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task')->runId;
            (new AdvanceDurableSwarm($runId, 0))->handle($manager);
            (new AdvanceDurableSwarm($runId, 1))->handle($manager);
            $manager->wait($runId, 'approval');
        }

        $row = joinWaitRunRow($runId);
        expect($row->status)->toBe('waiting')
            ->and($row->current_step_index)->toBeNull()
            ->and(DB::table('swarm_durable_branches')->where('run_id', $runId)->where('parent_node_id', $row->current_node_id)->count())->toBeGreaterThan(0);

        if ($profile !== 'top-level parallel') {
            // The boundary's node is named exactly like a lease's node for the next step.
            expect($row->current_node_id)->toBe('step:'.$row->next_step_index);
        }

        DB::table('swarm_durable_branches')->where('run_id', $runId)->delete();
        $before = joinWaitDispatches($runId);

        expect($manager->signal($runId, 'approval')->accepted)->toBeTrue()
            ->and($manager->find($runId)['status'])->toBe('waiting')
            ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
            ->and(joinWaitDispatches($runId))->toBe($before);
    })->with(['top-level parallel', 'step-durable hierarchical', 'queued-hierarchical']);

    test('a fail_run parent leased to be failed is not a fan-out: its last wait closing moves it to pending', function (bool $pausedBetween) {
        joinWaitRuntime();
        $runId = joinWaitParallelRun(completeBranches: false);
        $manager = app(DurableSwarmManager::class);
        $store = app(DurableRunStore::class);
        $manager->wait($runId, 'second');
        joinWaitMarkBranchesCompleted($runId);
        DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', 'parallel:0')->update(['status' => 'failed']);

        // What failWaitingParentFromBranches() does before it records the failure —
        // and then the worker is gone: the release, and a lease with no markRunning().
        expect($store)->toBeInstanceOf(ReleasesWaitingRunForFailure::class)
            ->and($store->releaseWaitingRunForFailure($runId, 3))->toBeTrue()
            ->and($store->acquireLease($runId, 3, 300))->not->toBeNull();

        $row = joinWaitRunRow($runId);
        expect($row->status)->toBe('pending')
            ->and($row->current_step_index)->toBeNull()
            ->and($row->current_node_id)->toBe('step:3')
            ->and((int) $row->next_step_index)->toBe(3);

        // One of the two waits closes: the run is waiting again, on the lease's node.
        expect($store->acceptSignal($runId, 'approval', null, null))->not->toBeNull()
            ->and(joinWaitRunRow($runId)->status)->toBe('waiting');

        if ($pausedBetween) {
            // pause() clears the lease token; the node the lease wrote stays.
            expect($store->pause($runId))->toBeTrue()
                ->and($store->resume($runId))->toBeTrue()
                ->and(joinWaitRunRow($runId)->status)->toBe('waiting');
        }

        // The last wait closes. No branch rows are at step:3, so the run must go on to
        // pending, where swarm:recover finds it — never stay waiting on nothing.
        expect($store->acceptSignal($runId, 'second', null, null))->not->toBeNull()
            ->and(joinWaitRunRow($runId)->status)->toBe('pending');
    })->with(['the lease still held' => false, 'paused and resumed in between' => true]);

    test('a run that has not fanned out goes to pending when its last wait closes', function (string $shape) {
        joinWaitRuntime();
        $manager = app(DurableSwarmManager::class);
        $store = app(DurableRunStore::class);

        if ($shape === 'never run') {
            $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
        } elseif ($shape === 'coordinator step done') {
            // The coordinator's checkpoint points the cursor at the parallel node, with
            // a next index past zero — but the step that ran left its step index set,
            // and the fan-out has not happened.
            FakeHierarchicalCoordinator::fake([joinWaitHierarchicalPlan()]);
            $runId = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task')->runId;
            (new AdvanceDurableSwarm($runId, 0))->handle($manager);
            $row = joinWaitRunRow($runId);
            expect($row->current_node_id)->toBe('parallel_node')
                ->and($row->current_step_index)->not->toBeNull()
                ->and((int) $row->next_step_index)->toBe(1)
                ->and(DB::table('swarm_durable_branches')->where('run_id', $runId)->count())->toBe(0);
        } else {
            // A hierarchical run's first step is leased at the `coordinator` node, with
            // no current step yet and its next index still zero.
            FakeHierarchicalCoordinator::fake([joinWaitHierarchicalPlan()]);
            $runId = FakeHierarchicalFullSwarm::make()->dispatchDurable('durable-hierarchical-task')->runId;
            expect($store->acquireLease($runId, 0, 300))->not->toBeNull();
            $row = joinWaitRunRow($runId);
            expect($row->current_node_id)->toBe('coordinator')
                ->and($row->current_step_index)->toBeNull()
                ->and((int) $row->next_step_index)->toBe(0);
        }

        $manager->wait($runId, 'approval');
        $before = joinWaitDispatches($runId);

        expect($manager->signal($runId, 'approval')->accepted)->toBeTrue()
            ->and($manager->find($runId)['status'])->toBe('pending')
            ->and(joinWaitDispatches($runId)['step'])->toBe($before['step'] + 1);
    })->with(['never run', 'first step leased', 'coordinator step done']);
});
