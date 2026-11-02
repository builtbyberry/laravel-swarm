<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\DurableDispatchType;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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
        DB::table('swarm_durable_branches')->where('run_id', $runId)->update([
            'status' => 'completed',
            'output' => 'branch-out',
            'usage' => json_encode([]),
            'duration_ms' => 1,
            'finished_at' => now('UTC'),
            'execution_token' => null,
            'leased_until' => null,
        ]);
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
        DB::table('swarm_durable_branches')->where('run_id', $runId)->update([
            'status' => 'completed',
            'output' => 'branch-out',
            'usage' => json_encode([]),
            'duration_ms' => 1,
            'finished_at' => now('UTC'),
            'execution_token' => null,
            'leased_until' => null,
        ]);
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
        DB::table('swarm_durable_branches')->where('run_id', $runId)->update([
            'status' => 'completed',
            'output' => 'branch-out',
            'usage' => json_encode([]),
            'duration_ms' => 1,
            'finished_at' => now('UTC'),
            'execution_token' => null,
            'leased_until' => null,
        ]);

        $manager->resume($runId);

        expect($manager->find($runId)['status'])->toBe('waiting')
            ->and(joinWaitDispatches($runId)['queued_resume'])->toBe(0);

        $manager->signal($runId, 'approval');

        expect(joinWaitDispatches($runId)['queued_resume'])->toBe(1);

        joinWaitResumeQueued($runId);

        expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
    });

    test('swarm:recover does not release the queued join while a wait is open', function () {
        $runId = 'join-wait-qh-recover';
        joinWaitQueuedRun($runId, completeBranches: false);
        $manager = app(DurableSwarmManager::class);

        DB::table('swarm_durable_branches')->where('run_id', $runId)->update([
            'status' => 'completed',
            'output' => 'branch-out',
            'usage' => json_encode([]),
            'duration_ms' => 1,
            'finished_at' => now('UTC'),
            'execution_token' => null,
            'leased_until' => null,
        ]);
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
    $logger = Mockery::mock(Psr\Log\LoggerInterface::class)->shouldIgnoreMissing();
    $logger->shouldReceive('error')->andReturnUsing(function (string|Stringable $message) use ($onBranchFailed, $throw): void {
        if (! str_contains((string) $message, 'branch failed')) {
            return;
        }

        if ($onBranchFailed !== null) {
            $onBranchFailed();
        }

        if ($throw) {
            throw new RuntimeException('logging unavailable');
        }
    });
    app()->instance(Psr\Log\LoggerInterface::class, $logger);
    app()->forgetInstance(DurableSwarmManager::class);
}

function joinWaitFailingPlan(): array
{
    return [
        'start_at' => 'parallel_node',
        'nodes' => [
            'parallel_node' => ['type' => 'parallel', 'branches' => ['writer_node', 'failing_node'], 'next' => 'finish_node'],
            'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
            'failing_node' => ['type' => 'worker', 'agent' => BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FailingPromptAgent::class, 'prompt' => 'failing-branch'],
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
    $failing = BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FailingPromptAgent::class;

    if ($profile === 'qh') {
        $runId = 'join-wait-fail-'.Illuminate\Support\Str::random(6);
        $context = RunContext::from('queued-hierarchical-task', $runId);
        (new InvokeSwarm(BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalParallelFailBranchSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));
    } else {
        $runId = BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelFailingSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
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
        Illuminate\Support\Facades\Event::fake([BuiltByBerry\LaravelSwarm\Events\SwarmFailed::class]);
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
    })->with(['step-durable parallel' => 'sd', 'queued-hierarchical' => 'qh']);

    test('a sibling finishing between the branch failure and the parent failure does not release the join', function (string $profile) {
        $manager = app(DurableSwarmManager::class);
        $runId = BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelFailingSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
        $branches = app(DurableRunStore::class)->branchesFor($runId, 'parallel');
        $failing = collect($branches)->firstWhere('agent_class', BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FailingPromptAgent::class);
        $siblings = collect($branches)->reject(fn (array $b): bool => $b['branch_id'] === $failing['branch_id'])->values();

        (new AdvanceDurableBranch($runId, $siblings[0]['branch_id']))->handle($manager);
        joinWaitBranchFailureLogger(function () use ($runId, $siblings): void {
            (new AdvanceDurableBranch($runId, $siblings[1]['branch_id']))->handle(app(DurableSwarmManager::class));
        });
        $before = joinWaitDispatches($runId);

        (new AdvanceDurableBranch($runId, $failing['branch_id']))->handle(app(DurableSwarmManager::class));

        joinWaitAssertFailedNotJoined($runId, $before);
    })->with(['step-durable parallel' => 'sd']);

    test('a failing worker that never reaches the parent leaves it to swarm:recover, which fails it', function (string $profile, bool $openWait) {
        joinWaitBranchFailureLogger(throw: true);
        [$runId, $failingBranchId] = joinWaitFailRunAtJoin($profile, openWait: $openWait);
        $manager = app(DurableSwarmManager::class);
        $before = joinWaitDispatches($runId);

        expect(fn () => (new AdvanceDurableBranch($runId, $failingBranchId))->handle($manager))->toThrow(RuntimeException::class, 'logging unavailable');
        expect($manager->find($runId)['status'])->toBe('waiting');

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
        Illuminate\Support\Facades\Event::assertDispatchedTimes(BuiltByBerry\LaravelSwarm\Events\SwarmFailed::class, 1);
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
        Illuminate\Support\Facades\Event::assertDispatchedTimes(BuiltByBerry\LaravelSwarm\Events\SwarmFailed::class, 1);
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

        $childRunId = $manager->dispatchChildSwarm($runId, BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm::class, 'child-task')->childRunId;
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
        Illuminate\Support\Facades\Exceptions::fake();
        $failJoinDispatch = false;
        $realOutbox = app(BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox::class);
        $outbox = Mockery::mock($realOutbox);
        $outbox->shouldReceive('enqueueStep')->andReturnUsing(function (...$args) use (&$failJoinDispatch, $realOutbox): int {
            if ($failJoinDispatch) {
                throw new RuntimeException('outbox unavailable');
            }

            return $realOutbox->enqueueStep(...$args);
        });
        app()->instance(BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox::class, $outbox);
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
        Illuminate\Support\Facades\Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'could not be released to its join'));

        $failJoinDispatch = false;
        joinWaitAgeRun($runId);
        Artisan::call('swarm:recover');

        expect($manager->find($runId)['status'])->toBe('pending');

        (new AdvanceDurableSwarm($runId, 3))->handle($manager);

        expect($manager->find($runId)['status'])->toBe('completed');
    });

    test('a timed-out wait whose outcome cannot be published is not left closed', function () {
        $failPublish = false;
        $realContextStore = app(ContextStore::class);
        $contextStore = Mockery::mock($realContextStore);
        $contextStore->shouldReceive('put')->andReturnUsing(function (...$args) use (&$failPublish, $realContextStore): void {
            if ($failPublish) {
                throw new RuntimeException('context store unavailable');
            }

            $realContextStore->put(...$args);
        });
        app()->instance(ContextStore::class, $contextStore);
        app()->forgetInstance(DurableSwarmManager::class);

        $runId = joinWaitParallelRun();
        $manager = app(DurableSwarmManager::class);
        DB::table('swarm_durable_waits')->where('run_id', $runId)->delete();
        $manager->wait($runId, 'approval', timeoutSeconds: 60);
        $this->travel(2)->minutes();
        $failPublish = true;

        expect(fn () => $manager->recover())->toThrow(RuntimeException::class, 'context store unavailable');

        expect($manager->inspect($runId)->waits[0]['status'])->toBe('waiting')
            ->and($manager->find($runId)['status'])->toBe('waiting');
    });

    test('every wait closer shares the factory\'s one join release', function () {
        $manager = app(DurableSwarmManager::class);
        $read = fn (object $object, string $property): mixed => (new ReflectionProperty($object, $property))->getValue($object);

        $hierarchical = $read($manager, 'hierarchicalCoordinator');
        $joinRelease = $read($hierarchical, 'joinRelease');

        expect($joinRelease)->toBeInstanceOf(BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJoinRelease::class)
            ->and($read($read($manager, 'signalHandler'), 'joinRelease'))->toBe($joinRelease)
            ->and($read($read($manager, 'children'), 'joinRelease'))->toBe($joinRelease)
            ->and($read($read($manager, 'recovery'), 'joinRelease'))->toBe($joinRelease)
            ->and($read($joinRelease, 'runs'))->toBe($read($read($manager, 'signalHandler'), 'runs'));
    });
});
