<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;
use BuiltByBerry\LaravelSwarm\Exceptions\DurableWaitSetChangedException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableLifecycleController;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\PausedWait\RetryDurableLifecycleController;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\PausedWait\RetryDurableRunStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ChildDispatchingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableWaitAttributeSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\OrdinaryBoundaryHierarchicalSwarm;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.durable.queue.connection', 'durable-test');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    app()->instance(DurableRunStore::class, app(RetryDurableRunStore::class));
    app()->bind(DurableLifecycleController::class, RetryDurableLifecycleController::class);
    FakeResearcher::fake(['research']);
    FakeWriter::fake(['written']);
    FakeHierarchicalCoordinator::fake([['start_at' => 'writer', 'nodes' => [
        'writer' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'write', 'next' => 'finish'],
        'finish' => ['type' => 'finish', 'output_from' => 'writer'],
    ]]]);
});

function pausedWaitRaceSql(string $sql): string
{
    return strtolower(str_replace(['"', '`', '[', ']'], '', $sql));
}

function pausedWaitRaceRun(): string
{
    $runId = FakeSequentialSwarm::make()->dispatchDurable('paused-race')->runId;
    app(DurableSwarmManager::class)->pause($runId);

    return $runId;
}

function pausedWaitRaceStepRows(string $runId): int
{
    return DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'step')->count();
}

function pausedWaitRaceDeadlock(): QueryException
{
    return new QueryException('testing', 'injected resume deadlock', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction', 40001));
}

// Same-connection mutation, rolled back with the failed attempt. This proves retry
// control flow, not cross-connection visibility or SQLite concurrent commits.
test('a changed scan generation rolls back and retries once from a fresh transaction', function (bool $waitOnRetry): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->scan = function (string $id) use ($store): void {
        if ($store->attempts === 1) {
            DB::table('swarm_durable_runs')->where('run_id', $id)->increment('wait_generation');
        }
    };
    $store->beforeResume = function (string $id) use ($store, $waitOnRetry): void {
        if ($waitOnRetry && $store->attempts === 2) {
            $store->createWait($id, 'retry-wait');
        }
    };
    $before = pausedWaitRaceStepRows($runId);

    expect(app(DurableSwarmManager::class)->resume($runId)->status->value)->toBe($waitOnRetry ? 'waiting' : 'resumed');
    expect($store->attempts)->toBe(2)
        ->and((string) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('wait_generation'))->toBe($waitOnRetry ? '1' : '0')
        ->and(pausedWaitRaceStepRows($runId))->toBe($before + ($waitOnRetry ? 0 : 1));
})->with(['retry pending' => false, 'retry waiting' => true]);

test('the database store signals a changed empty scan without writing pending', function (): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->scan = fn (string $id) => DB::table('swarm_durable_runs')->where('run_id', $id)->increment('wait_generation');

    expect(fn () => $store->resume($runId))->toThrow(DurableWaitSetChangedException::class);
    expect($store->find($runId)['status'])->toBe('paused')->and($store->attempts)->toBe(1);
});

test('a generation change inside an application transaction refuses without retry', function (): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->scan = fn (string $id) => DB::table('swarm_durable_runs')->where('run_id', $id)->increment('wait_generation');
    $before = pausedWaitRaceStepRows($runId);

    expect(fn () => DB::transaction(fn () => app(DurableSwarmManager::class)->resume($runId)))
        ->toThrow(SwarmException::class, 'retry the resume outside this transaction');
    expect($store->attempts)->toBe(1)->and($store->find($runId)['status'])->toBe('paused')
        ->and(pausedWaitRaceStepRows($runId))->toBe($before);
});

test('changing waits exhaust exactly three attempts and leave the run paused', function (): void {
    Event::fake([SwarmResumed::class]);
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->scan = fn (string $id) => DB::table('swarm_durable_runs')->where('run_id', $id)->increment('wait_generation');
    $before = pausedWaitRaceStepRows($runId);

    expect(fn () => app(DurableSwarmManager::class)->resume($runId))
        ->toThrow(SwarmException::class, 'waits on it kept changing');
    expect($store->attempts)->toBe(3)->and($store->find($runId)['status'])->toBe('paused')
        ->and(pausedWaitRaceStepRows($runId))->toBe($before);
    Event::assertNotDispatched(SwarmResumed::class);
});

test('a resume victim retries once with one committed event outbox and eager push', function (string $edge): void {
    Event::fake([SwarmResumed::class]);
    $runId = pausedWaitRaceRun();
    config()->set('swarm.durable.eager_dispatch', true);
    Queue::fake();
    $store = app(DurableRunStore::class);
    $failed = false;
    $before = pausedWaitRaceStepRows($runId);
    if ($edge === 'nested store') {
        $store->beforeResume = function () use (&$failed): void {
            if (! $failed) {
                $failed = true;
                throw pausedWaitRaceDeadlock();
            }
        };
    } else {
        // QueryExecuted runs after INSERT: history, run update and outbox write
        // must all roll back, including the discarded attempt's after-commit push.
        DB::listen(function (QueryExecuted $query) use (&$failed): void {
            if (! $failed && str_starts_with(pausedWaitRaceSql($query->sql), 'insert into swarm_durable_outbox')) {
                $failed = true;
                throw pausedWaitRaceDeadlock();
            }
        });
    }

    expect(app(DurableSwarmManager::class)->resume($runId)->status->value)->toBe('resumed');
    expect($failed)->toBeTrue()->and($store->attempts)->toBe(2)
        ->and($store->find($runId)['status'])->toBe('pending')
        ->and(DB::table('swarm_run_histories')->where('run_id', $runId)->value('status'))->toBe('pending')
        ->and(pausedWaitRaceStepRows($runId))->toBe($before + 1);
    Event::assertDispatchedTimes(SwarmResumed::class, 1);
    Queue::assertPushed(AdvanceDurableSwarm::class, fn ($job): bool => $job->runId === $runId && $job->outboxEntryId !== null);
    Queue::assertPushed(AdvanceDurableSwarm::class, 1);
})->with(['nested store', 'after outbox insert']);

test('generation changes and concurrency victims share one three-attempt budget', function (): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->beforeResume = function () use ($store): void {
        if ($store->attempts === 2) {
            throw pausedWaitRaceDeadlock();
        }
    };
    $store->scan = fn (string $id) => DB::table('swarm_durable_runs')->where('run_id', $id)->increment('wait_generation');
    expect(fn () => app(DurableSwarmManager::class)->resume($runId))->toThrow(SwarmException::class, 'waits on it kept changing');
    expect($store->attempts)->toBe(3)->and($store->find($runId)['status'])->toBe('paused');
});

test('a concurrency victim inside an application transaction belongs to the application', function (): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->beforeResume = fn () => throw pausedWaitRaceDeadlock();
    expect(fn () => DB::transaction(fn () => app(DurableSwarmManager::class)->resume($runId)))->toThrow(DeadlockException::class, 'Deadlock');
    expect($store->attempts)->toBe(1)->and($store->find($runId)['status'])->toBe('paused');
});

test('a failure inside ordinary boundary persistence rolls back its checkpoint', function (string $swarm, string $failure): void {
    $runId = $swarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    $store = app(DurableRunStore::class);
    $before = null;
    $manager->beforeStepCheckpointForTesting(function () use ($runId, $store, &$before): void {
        $before = $store->find($runId);
    });
    $store->{$failure} = fn () => throw new RuntimeException('inside boundary persistence');
    $stepRows = pausedWaitRaceStepRows($runId);

    expect(fn () => (new AdvanceDurableSwarm($runId, 0))->handle($manager))->toThrow(RuntimeException::class, 'inside boundary persistence');
    $after = $store->find($runId);
    expect($after['next_step_index'])->toBe($before['next_step_index'])
        ->and($after['status'])->toBe($before['status'])
        ->and($after['execution_token'])->toBe($before['execution_token'])
        ->and($store->waits($runId))->toBe([])
        ->and($store->childRuns($runId))->toBe([])
        ->and(app(ContextStore::class)->find($runId)['metadata'])->not->toHaveKey('durable_dispatched_child_swarms')
        ->and(pausedWaitRaceStepRows($runId))->toBe($stepRows);
})->with([
    'declared wait' => [DurableWaitAttributeSwarm::class, 'beforeCreate'],
    'hierarchical wait' => [OrdinaryBoundaryHierarchicalSwarm::class, 'beforeCreate'],
    'child before wait' => [ChildDispatchingSwarm::class, 'beforeCreate'],
    'child after wait and intent insert' => [ChildDispatchingSwarm::class, 'afterChildRun'],
]);

test('a failure after cursor persistence before the boundary rolls the checkpoint back', function (): void {
    $runId = DurableWaitAttributeSwarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    $before = null;
    $manager->beforeStepCheckpointForTesting(function () use ($manager, $runId, &$before): void {
        $before = $manager->find($runId);
    });
    app(DurableRunStore::class)->afterRelease = fn () => throw new RuntimeException('before boundary');
    $rows = pausedWaitRaceStepRows($runId);
    expect(fn () => (new AdvanceDurableSwarm($runId, 0))->handle($manager))->toThrow(RuntimeException::class, 'before boundary');
    expect($manager->find($runId)['next_step_index'])->toBe($before['next_step_index'])
        ->and($manager->find($runId)['execution_token'])->toBe($before['execution_token'])
        ->and(app(DurableRunStore::class)->waits($runId))->toBe([])
        ->and(pausedWaitRaceStepRows($runId))->toBe($rows);
});

test('an entered ordinary boundary never inserts a continuation step entry', function (bool $eager): void {
    config()->set('swarm.durable.eager_dispatch', $eager);
    Queue::fake();
    $runId = DurableWaitAttributeSwarm::make()->dispatchDurable('task')->runId;
    $insertions = 0;
    DB::listen(function (QueryExecuted $query) use (&$insertions): void {
        if (str_starts_with(pausedWaitRaceSql($query->sql), 'insert into swarm_durable_outbox')) {
            $insertions++;
        }
    });
    $rows = pausedWaitRaceStepRows($runId);
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    expect($insertions)->toBe(0)
        ->and(pausedWaitRaceStepRows($runId))->toBe($rows)
        ->and(app(DurableRunStore::class)->find($runId)['status'])->toBe('waiting');
})->with(['relay' => false, 'eager' => true]);

test('a boundary outcome already present follows the ordinary enqueue path', function (): void {
    $context = RunContext::from('task');
    $context->mergeMetadata(['durable_wait_outcomes' => ['approval_received' => ['status' => 'signalled', 'payload' => true, 'timed_out' => false]]]);
    $runId = DurableWaitAttributeSwarm::make()->dispatchDurable($context)->runId;
    $before = pausedWaitRaceStepRows($runId);
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    expect(app(DurableRunStore::class)->find($runId)['status'])->toBe('pending')
        ->and(app(DurableRunStore::class)->waits($runId))->toBe([])
        ->and(pausedWaitRaceStepRows($runId))->toBe($before + 1);
});

test('ordinary boundary notification failure at level zero propagates after commit', function (): void {
    $runId = DurableWaitAttributeSwarm::make()->dispatchDurable('task')->runId;
    $level = null;
    Event::listen(SwarmWaiting::class, function () use (&$level): void {
        $level = DB::connection()->transactionLevel();
        throw new RuntimeException('notification after commit');
    });
    expect(fn () => (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class)))
        ->toThrow(RuntimeException::class, 'notification after commit');
    expect($level)->toBe(0)->and(app(DurableRunStore::class)->find($runId)['status'])->toBe('waiting')
        ->and(app(DurableRunStore::class)->find($runId)['next_step_index'])->toBe(1)
        ->and(app(DurableRunStore::class)->waits($runId))->toHaveCount(1);
});

test('nested ordinary effects wait for commit and report without discarding later callbacks', function (): void {
    $runId = DurableWaitAttributeSwarm::make()->dispatchDurable('task')->runId;
    Exceptions::fake();
    $notifications = 0;
    $callback = false;
    Event::listen(SwarmWaiting::class, function () use (&$notifications): void {
        $notifications++;
        throw new RuntimeException('nested notification');
    });
    DB::transaction(function () use ($runId, &$notifications, &$callback): void {
        (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
        expect($notifications)->toBe(0);
        DB::connection()->afterCommit(function () use (&$callback): void {
            $callback = true;
        });
    });
    expect($notifications)->toBe(1)->and($callback)->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
});

test('rolling back an application checkpoint discards its ordinary boundary effects', function (): void {
    $runId = DurableWaitAttributeSwarm::make()->dispatchDurable('task')->runId;
    $notifications = 0;
    Event::listen(SwarmWaiting::class, function () use (&$notifications): void {
        $notifications++;
    });
    DB::beginTransaction();
    try {
        (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    } finally {
        DB::rollBack();
    }
    expect($notifications)->toBe(0)->and(app(DurableRunStore::class)->waits($runId))->toBe([])
        ->and(app(DurableRunStore::class)->find($runId)['next_step_index'])->toBe(0);
});

test('generation observation precedes the wait scan', function (): void {
    $runId = pausedWaitRaceRun();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = pausedWaitRaceSql($query->sql);
    });
    app(DurableRunStore::class)->resume($runId);
    $observed = array_find_key($queries, fn (string $sql): bool => str_starts_with($sql, 'select ') && str_contains($sql, 'wait_generation') && str_contains($sql, 'swarm_durable_runs'));
    $scanned = array_find_key($queries, fn (string $sql): bool => str_starts_with($sql, 'select ') && str_contains($sql, 'swarm_durable_waits'));
    expect($observed)->not->toBeNull()->and($scanned)->not->toBeNull()->and($observed)->toBeLessThan($scanned);
});

test('an observed open wait wins over a conservative generation mismatch', function (): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->createWait($runId, 'seen');
    $store->scan = fn (string $id) => DB::table('swarm_durable_runs')->where('run_id', $id)->increment('wait_generation');
    expect(app(DurableSwarmManager::class)->resume($runId)->status->value)->toBe('waiting')
        ->and($store->attempts)->toBe(1)->and($store->find($runId)['status'])->toBe('waiting');
});

test('rolled back wait creation does not poison a later resume generation', function (): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    expect(fn () => DB::transaction(function () use ($store, $runId): void {
        $store->createWait($runId, 'rolled-back');
        throw new RuntimeException('creator rollback');
    }))->toThrow(RuntimeException::class, 'creator rollback');
    expect(app(DurableSwarmManager::class)->resume($runId)->status->value)->toBe('resumed')
        ->and($store->attempts)->toBe(1)->and($store->waits($runId))->toBe([])
        ->and((string) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('wait_generation'))->toBe('0');
});

test('ordinary persistence victims retry the checkpoint without reexecuting the agent', function (string $swarm, string $failure): void {
    $calls = 0;
    FakeResearcher::fake(function () use (&$calls): string {
        $calls++;

        return 'research';
    });
    FakeHierarchicalCoordinator::fake(function () use (&$calls): array {
        $calls++;

        return ['start_at' => 'writer', 'nodes' => [
            'writer' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'write', 'next' => 'finish'],
            'finish' => ['type' => 'finish', 'output_from' => 'writer'],
        ]];
    });
    Event::fake([SwarmWaiting::class]);
    $runId = $swarm::make()->dispatchDurable('task')->runId;
    $store = app(DurableRunStore::class);
    $persists = 0;
    $before = null;
    app(DurableSwarmManager::class)->beforeStepCheckpointForTesting(function () use ($runId, $store, &$before): void {
        $before = $store->find($runId);
    });
    $checkpoints = 0;
    $store->beforeRelease = function () use ($runId, $store, &$before, &$checkpoints): void {
        $checkpoints++;
        expect($store->find($runId)['next_step_index'])->toBe($before['next_step_index'])
            ->and($store->waits($runId))->toBe([])
            ->and($store->childRuns($runId))->toBe([])
            ->and(app(ContextStore::class)->find($runId)['metadata'])->not->toHaveKey('durable_dispatched_child_swarms');
    };
    $store->{$failure} = function () use (&$persists): void {
        if (++$persists === 1) {
            throw pausedWaitRaceDeadlock();
        }
    };
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    expect($persists)->toBe(2)->and($checkpoints)->toBe($swarm === OrdinaryBoundaryHierarchicalSwarm::class ? 0 : 2)->and($calls)->toBe(1)
        ->and($store->waits($runId))->toHaveCount(1)->and($store->find($runId)['status'])->toBe('waiting')
        ->and($store->find($runId)['next_step_index'])->toBe($before['next_step_index'] + 1)
        ->and($store->childRuns($runId))->toHaveCount($swarm === ChildDispatchingSwarm::class ? 1 : 0);
    Event::assertDispatchedTimes(SwarmWaiting::class, 1);
})->with([
    'declared wait' => [DurableWaitAttributeSwarm::class, 'beforeCreate'],
    'hierarchical wait' => [OrdinaryBoundaryHierarchicalSwarm::class, 'beforeCreate'],
    'child before wait' => [ChildDispatchingSwarm::class, 'beforeCreate'],
    'child after wait and intent insert' => [ChildDispatchingSwarm::class, 'afterChildRun'],
]);

test('the same-connection postcommit hook sees the boundary and rejects the next lease', function (string $swarm): void {
    $runId = $swarm::make()->dispatchDurable('boundary-probe')->runId;
    $manager = app(DurableSwarmManager::class);
    $store = app(DurableRunStore::class);
    $observed = null;
    expect(DB::connection()->transactionLevel())->toBe(0);
    $manager->afterStepCheckpointForTesting(function (string $id, int $next) use ($store, &$observed): void {
        expect(DB::connection()->transactionLevel())->toBe(0);
        $status = $store->find($id)['status'];
        $open = array_filter($store->waits($id), fn (array $wait): bool => $wait['status'] === 'waiting');
        DB::beginTransaction();
        try {
            $leased = $store->acquireLease($id, $next, 300) !== null;
        } finally {
            DB::rollBack();
        }
        $observed = ['status' => $status, 'open' => count($open), 'leased' => $leased];
    });
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    expect($observed)->toBe(['status' => 'waiting', 'open' => 1, 'leased' => false]);
})->with([DurableWaitAttributeSwarm::class, ChildDispatchingSwarm::class, OrdinaryBoundaryHierarchicalSwarm::class]);

test('resume exhaustion reports the last failure and preserves it as previous', function (string $last): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $controller = app(DurableLifecycleController::class);
    $failure = null;
    $store->beforeResume = function () use ($store, $last, &$failure): void {
        $failure = ($store->attempts === 3 ? $last === 'concurrency' : $last !== 'concurrency')
            ? pausedWaitRaceDeadlock()
            : new DurableWaitSetChangedException;
        throw $failure;
    };
    $before = pausedWaitRaceStepRows($runId);
    try {
        $controller->resume($runId, fn (): bool => false);
        test()->fail('Expected resume exhaustion.');
    } catch (SwarmException $exception) {
        expect($exception->getMessage())->toBe("Durable run [{$runId}] could not be resumed: ".($last === 'concurrency'
            ? 'the database kept reporting concurrency conflicts' : 'waits on it kept changing').'; retry the resume.')
            ->and($exception->getPrevious())->toBeInstanceOf($last === 'concurrency' ? DeadlockException::class : DurableWaitSetChangedException::class);
        if ($last === 'concurrency') {
            expect($exception->getPrevious()->getPrevious())->toBe($failure);
        } else {
            expect($exception->getPrevious())->toBe($failure);
        }
    }
    expect($store->attempts)->toBe(3)->and($store->find($runId)['status'])->toBe('paused')
        ->and(pausedWaitRaceStepRows($runId))->toBe($before)
        ->and($controller->backoffs)->toBe($last === 'concurrency' ? [] : [1, 2]);
})->with(['concurrency', 'generation']);

test('a lost resume race reports the freshly locked status or a vanished run', function (?string $status): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->beforeResume = function (string $id) use ($status): void {
        $query = DB::table('swarm_durable_runs')->where('run_id', $id);
        if ($status === null) {
            $query->delete();
        } else {
            $query->update(['status' => $status]);
        }
    };
    expect(fn () => app(DurableSwarmManager::class)->resume($runId))->toThrow(SwarmException::class,
        $status === null ? "Durable run [{$runId}] could not be resumed." : "Durable run [{$runId}] cannot be resumed from status [{$status}].");
})->with(['pending', 'cancelled', null]);

test('a lost resume race inside an application transaction names no possibly stale status', function (): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->beforeResume = function (string $id): void {
        DB::table('swarm_durable_runs')->where('run_id', $id)->update(['status' => 'cancelled']);
    };
    DB::beginTransaction();
    try {
        expect(fn () => app(DurableSwarmManager::class)->resume($runId))
            ->toThrow(SwarmException::class, "Durable run [{$runId}] could not be resumed: it is no longer paused.");
    } finally {
        DB::rollBack();
    }
});

test('a locked fan-out keeps resume waiting when its branch read sees no rows', function (): void {
    $runId = FakeParallelSwarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $manager->pause($runId);
    $store = app(DurableRunStore::class);
    expect($store->branchesFor($runId))->not->toBeEmpty();
    // SQLite cannot provide an old snapshot plus a current locked row. Hide the
    // branch read in the test store while retaining the real waitForBranches row.
    $store->hideBranches = true;
    expect($store->branchesFor($runId))->toBe([]);
    $before = pausedWaitRaceStepRows($runId);
    expect(app(DurableLifecycleController::class)->resume($runId, fn (): bool => false)->status->value)->toBe('waiting')
        ->and($store->find($runId)['status'])->toBe('waiting')
        ->and(pausedWaitRaceStepRows($runId))->toBe($before);
});
