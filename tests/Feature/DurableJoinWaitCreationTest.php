<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJoinRelease;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\JoinBoundaryChildSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\JoinBoundaryStaticWaitSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\JoinBoundaryWaitSwarm;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

function joinCreationTerminalBranches(string $runId): void
{
    DB::table('swarm_durable_branches')->where('run_id', $runId)->update([
        'status' => 'completed', 'output' => 'branch-output', 'finished_at' => now('UTC'),
        'execution_token' => null, 'leased_until' => null,
    ]);
}

function joinCreationPlan(): array
{
    return ['start_at' => 'parallel_node', 'nodes' => [
        'parallel_node' => ['type' => 'parallel', 'branches' => ['writer_node', 'editor_node'], 'next' => 'finish_node'],
        'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'write'],
        'editor_node' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'edit'],
        'finish_node' => ['type' => 'finish', 'output_from' => 'editor_node'],
    ]];
}

beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.durable.queue.connection', 'durable-test');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

test('late explicit waits are rejected before wait rows or events are published (#614)', function (): void {
    $manager = app(DurableSwarmManager::class);
    $runId = FakeParallelSwarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    joinCreationTerminalBranches($runId);
    expect(app(DurableJoinRelease::class)->releaseIfReady($manager->find($runId)))->toBeTrue();
    $notifications = 0;
    Event::listen(SwarmWaiting::class, function () use (&$notifications): void {
        $notifications++;
    });
    $before = DB::table('swarm_durable_outbox')->where('run_id', $runId)->count();

    expect(fn () => $manager->wait($runId, 'late'))->toThrow(SwarmException::class, 'join');
    expect(app(DurableRunStore::class)->waits($runId))->toBe([])
        ->and($manager->find($runId)['status'])->toBe('pending')
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $runId)->count())->toBe($before);
    expect($notifications)->toBe(0);
});

test('ordinary pending steps and running durable joins still accept waits (#614)', function (): void {
    $manager = app(DurableSwarmManager::class);
    $ordinary = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $manager->wait($ordinary, 'ordinary');
    expect($manager->find($ordinary)['status'])->toBe('waiting');

    $runId = FakeParallelSwarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    joinCreationTerminalBranches($runId);
    app(DurableJoinRelease::class)->releaseIfReady($manager->find($runId));
    $store = app(DurableRunStore::class);
    $token = $store->acquireLease($runId, 3, 60);
    $store->markRunning($runId, $token, 3);
    $manager->wait($runId, 'running-join');
    expect($store->waits($runId)[0]['name'])->toBe('running-join');
});

test('declared boundaries exist before a fast join can release after fanout (#614)', function (string $swarm): void {
    FakeHierarchicalCoordinator::fake([joinCreationPlan()]);
    $manager = app(DurableSwarmManager::class);
    $runId = $swarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $released = null;
    $manager->afterStepCheckpointForTesting(function () use ($manager, $runId, &$released): void {
        joinCreationTerminalBranches($runId);
        $released = app(DurableJoinRelease::class)->releaseIfReady($manager->find($runId));
    });
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    expect($released)->toBeFalse()
        ->and($manager->find($runId)['status'])->toBe('waiting')
        ->and(app(DurableRunStore::class)->waits($runId))->toHaveCount(1);
    if ($swarm === JoinBoundaryChildSwarm::class) {
        expect(app(DurableRunStore::class)->childRuns($runId))->toHaveCount(1);
    } else {
        expect(app(DurableRunStore::class)->waits($runId)[0]['name'])->toBe('declared-approval');
    }
})->with([JoinBoundaryWaitSwarm::class, JoinBoundaryChildSwarm::class]);

test('fanout child intent survives an after-intent crash and is recovered once (#614)', function (): void {
    FakeHierarchicalCoordinator::fake([joinCreationPlan()]);
    $manager = app(DurableSwarmManager::class);
    $runId = JoinBoundaryChildSwarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $manager->afterChildIntentForTesting(fn () => throw new RuntimeException('after intent'));
    expect(fn () => (new AdvanceDurableSwarm($runId, 1))->handle($manager))->toThrow(RuntimeException::class, 'after intent');
    $children = app(DurableRunStore::class)->childRuns($runId);
    expect($children)->toHaveCount(1)
        ->and($children[0]['dispatched_at'])->toBeNull()
        ->and($manager->find($runId)['next_step_index'])->toBe(3)
        ->and(app(DurableRunStore::class)->branchesFor($runId, 'parallel_node'))->toHaveCount(2);
    $manager->afterChildIntentForTesting(null);
    $manager->recover(runId: $runId);
    $manager->recover(runId: $runId);
    expect(app(DurableRunStore::class)->childRuns($runId))->toHaveCount(1)
        ->and($manager->find($children[0]['child_run_id']))->not->toBeNull();
});

test('fanout effects wait for the swarm outer commit despite another connection committing (#614)', function (): void {
    FakeHierarchicalCoordinator::fake([joinCreationPlan()]);
    $manager = app(DurableSwarmManager::class);
    $runId = JoinBoundaryChildSwarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    config()->set('database.connections.join-other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = DB::connection();
    $other = DB::connection('join-other');
    $transactions = new DatabaseTransactionsManager;
    $original = app('db.transactions');
    app()->instance('db.transactions', $transactions);
    $connection->setTransactionManager($transactions);
    $other->setTransactionManager($transactions);
    $notifications = 0;
    Event::listen(SwarmWaiting::class, function () use (&$notifications): void {
        $notifications++;
    });
    try {
        $connection->beginTransaction();
        $manager->afterStepCheckpointForTesting(fn () => $other->beginTransaction());
        (new AdvanceDurableSwarm($runId, 1))->handle($manager);
        $child = app(DurableRunStore::class)->childRuns($runId)[0];
        expect($manager->find($child['child_run_id']))->toBeNull()->and($notifications)->toBe(0);
        $other->commit();
        expect($manager->find($child['child_run_id']))->toBeNull()->and($notifications)->toBe(0);
        $connection->commit();
        expect($manager->find($child['child_run_id']))->not->toBeNull()->and($notifications)->toBe(1);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        while ($other->transactionLevel() > 0) {
            $other->rollBack();
        }
        app()->instance('db.transactions', $original);
        $connection->setTransactionManager($original);
        $other->setTransactionManager($original);
    }
});

test('rolling back a fanout discards its waits, child intents and notifications (#614)', function (): void {
    FakeHierarchicalCoordinator::fake([joinCreationPlan()]);
    $manager = app(DurableSwarmManager::class);
    $runId = JoinBoundaryChildSwarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $notifications = 0;
    Event::listen(SwarmWaiting::class, function () use (&$notifications): void {
        $notifications++;
    });
    $connection = DB::connection();
    $connection->beginTransaction();
    try {
        (new AdvanceDurableSwarm($runId, 1))->handle($manager);
        $child = app(DurableRunStore::class)->childRuns($runId)[0];
        expect($manager->find($child['child_run_id']))->toBeNull();
    } finally {
        $connection->rollBack();
    }
    expect(app(DurableRunStore::class)->waits($runId))->toBe([])
        ->and(app(DurableRunStore::class)->childRuns($runId))->toBe([])
        ->and(app(DurableRunStore::class)->branchesFor($runId, 'parallel_node'))->toBe([])
        ->and($manager->find($child['child_run_id']))->toBeNull();
    expect($notifications)->toBe(0);
});

test('a boundary notification failure after fanout leaves committed intent and eagerly pushes branches (#614)', function (): void {
    config()->set('swarm.durable.eager_dispatch', true);
    FakeHierarchicalCoordinator::fake([joinCreationPlan()]);
    Queue::fake();
    $manager = app(DurableSwarmManager::class);
    $runId = JoinBoundaryChildSwarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    Event::listen(SwarmWaiting::class, fn () => throw new RuntimeException('notification failed'));
    expect(fn () => (new AdvanceDurableSwarm($runId, 1))->handle($manager))->toThrow(RuntimeException::class, 'notification failed');
    expect(app(DurableRunStore::class)->waits($runId))->toHaveCount(1)
        ->and(app(DurableRunStore::class)->childRuns($runId))->toHaveCount(1)
        ->and($manager->find($runId)['next_step_index'])->toBe(3);
    Queue::assertPushed(AdvanceDurableBranch::class, 2);
});

test('a nested boundary effect failure does not discard later application commit callbacks (#614)', function (): void {
    FakeHierarchicalCoordinator::fake([joinCreationPlan()]);
    $manager = app(DurableSwarmManager::class);
    $runId = JoinBoundaryChildSwarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    Exceptions::fake();
    Event::listen(SwarmWaiting::class, fn () => throw new RuntimeException('notification failed'));
    $connection = DB::connection();
    $applicationCallback = false;
    $connection->transaction(function () use ($runId, $manager, $connection, &$applicationCallback): void {
        (new AdvanceDurableSwarm($runId, 1))->handle($manager);
        $connection->afterCommit(function () use (&$applicationCallback): void {
            $applicationCallback = true;
        });
    });
    expect($applicationCallback)->toBeTrue()
        ->and(app(DurableRunStore::class)->childRuns($runId))->toHaveCount(1);
    Exceptions::assertReported(RuntimeException::class);
});

test('fanout child first dispatch retains real input with capture disabled (#614)', function (): void {
    config()->set('swarm.capture.inputs', false);
    FakeHierarchicalCoordinator::fake([joinCreationPlan()]);
    $manager = app(DurableSwarmManager::class);
    $runId = JoinBoundaryChildSwarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    $child = app(DurableRunStore::class)->childRuns($runId)[0];
    expect($child['context_payload']['input'])->toBe('[redacted]')
        ->and(app(ContextStore::class)->find($child['child_run_id'])['input'])->toBe('operational-child-input');
});

function joinCreationInjectDeadlock(string $table): Closure
{
    $state = (object) ['fail' => true];
    DB::connection()->beforeExecuting(function (string $sql) use ($table, $state): void {
        if ($state->fail && str_starts_with(strtolower($sql), 'insert into') && str_contains($sql, $table)) {
            $state->fail = false;
            throw new QueryException('testing', $sql, [], new PDOException('Deadlock found when trying to get lock; try restarting transaction', 40001));
        }
    });

    return fn (): bool => $state->fail;
}

test('wait creation retries a rolled back concurrency error without duplicate rows (#614)', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $notTriggered = joinCreationInjectDeadlock('swarm_durable_waits');
    app(DurableSwarmManager::class)->wait($runId, 'retry');
    expect($notTriggered())->toBeFalse()->and(app(DurableRunStore::class)->waits($runId))->toHaveCount(1);
});

test('child intent retries at its outer transaction and publishes one wait (#614)', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $notTriggered = joinCreationInjectDeadlock('swarm_durable_waits');
    app(DurableSwarmManager::class)->dispatchChildSwarm($runId, FakeSequentialSwarm::class, 'child');
    expect($notTriggered())->toBeFalse()->and(app(DurableRunStore::class)->childRuns($runId))->toHaveCount(1)
        ->and(app(DurableRunStore::class)->waits($runId))->toHaveCount(1);
});

test('fanout retries discard collected effects and roll back child dedupe metadata (#614)', function (): void {
    FakeHierarchicalCoordinator::fake([joinCreationPlan()]);
    $manager = app(DurableSwarmManager::class);
    $runId = JoinBoundaryChildSwarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $notTriggered = joinCreationInjectDeadlock('swarm_durable_outbox');
    $notifications = 0;
    Event::listen(SwarmWaiting::class, function () use (&$notifications): void {
        $notifications++;
    });
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    expect($notTriggered())->toBeFalse()->and($notifications)->toBe(1)
        ->and(app(DurableRunStore::class)->childRuns($runId))->toHaveCount(1)
        ->and(app(DurableRunStore::class)->waits($runId))->toHaveCount(1)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'branch')->count())->toBe(2);
});

test('join release retries its outbox transaction without duplicate continuation (#614)', function (): void {
    $manager = app(DurableSwarmManager::class);
    $runId = FakeParallelSwarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    joinCreationTerminalBranches($runId);
    $before = DB::table('swarm_durable_outbox')->where('run_id', $runId)->count();
    $notTriggered = joinCreationInjectDeadlock('swarm_durable_outbox');
    expect(app(DurableJoinRelease::class)->releaseIfReady($manager->find($runId)))->toBeTrue()
        ->and($notTriggered())->toBeFalse()
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $runId)->count())->toBe($before + 1);
});

test('static hierarchical fanout also commits its declared wait before the join (#614)', function (): void {
    $manager = app(DurableSwarmManager::class);
    $runId = JoinBoundaryStaticWaitSwarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $released = null;
    $manager->afterStepCheckpointForTesting(function () use ($manager, $runId, &$released): void {
        joinCreationTerminalBranches($runId);
        $released = app(DurableJoinRelease::class)->releaseIfReady($manager->find($runId));
    });
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    expect($released)->toBeFalse()->and(app(DurableRunStore::class)->waits($runId)[0]['name'])->toBe('static-approval');
});

test('queued hierarchical released joins reject late waits as well (#614)', function (): void {
    config()->set('queue.default', 'null');
    config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');
    FakeHierarchicalCoordinator::fake([joinCreationPlan()]);
    $runId = 'queued-late-wait';
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, RunContext::from('task', $runId)->toQueuePayload()))->handle(app(SwarmRunner::class));
    joinCreationTerminalBranches($runId);
    $manager = app(DurableSwarmManager::class);
    expect(app(DurableJoinRelease::class)->releaseIfReady($manager->find($runId)))->toBeTrue();
    expect(fn () => $manager->wait($runId, 'late-queued'))->toThrow(SwarmException::class, 'join');
    expect(app(DurableRunStore::class)->waits($runId))->toBe([]);
});

test('wait notifications follow the committed fanout and its dispatch records (#614)', function (string $swarm): void {
    FakeHierarchicalCoordinator::fake([joinCreationPlan()]);
    $manager = app(DurableSwarmManager::class);
    $runId = $swarm::make()->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $observed = [];
    Event::listen(SwarmWaiting::class, function () use ($runId, &$observed): void {
        $observed[] = [
            'level' => DB::connection()->transactionLevel(),
            'branches' => DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'branch')->count(),
        ];
    });
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);
    expect($observed)->toBe([['level' => 0, 'branches' => 2]]);
})->with([JoinBoundaryWaitSwarm::class, JoinBoundaryChildSwarm::class]);
