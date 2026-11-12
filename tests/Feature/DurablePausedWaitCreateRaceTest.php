<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;
use BuiltByBerry\LaravelSwarm\Exceptions\DurableWaitSetChangedException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\PausedWait\RetryDurableRunStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ChildDispatchingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableWaitAttributeSwarm;
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
    FakeResearcher::fake(['research']);
    FakeWriter::fake(['written']);
    FakeHierarchicalCoordinator::fake([['start_at' => 'writer', 'nodes' => [
        'writer' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'write', 'next' => 'finish'],
        'finish' => ['type' => 'finish', 'output_from' => 'writer'],
    ]]]);
});

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
test('G5 a changed scan generation rolls back and retries once from a fresh transaction', function (bool $waitOnRetry): void {
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

test('G5 the database store signals a changed empty scan without writing pending', function (): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->scan = fn (string $id) => DB::table('swarm_durable_runs')->where('run_id', $id)->increment('wait_generation');

    expect(fn () => $store->resume($runId))->toThrow(DurableWaitSetChangedException::class);
    expect($store->find($runId)['status'])->toBe('paused')->and($store->attempts)->toBe(1);
});

test('G5 a generation change inside an application transaction refuses without retry', function (): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->scan = fn (string $id) => DB::table('swarm_durable_runs')->where('run_id', $id)->increment('wait_generation');
    $before = pausedWaitRaceStepRows($runId);

    expect(fn () => DB::transaction(fn () => app(DurableSwarmManager::class)->resume($runId)))
        ->toThrow(SwarmException::class, 'retry the resume outside this transaction');
    expect($store->attempts)->toBe(1)->and($store->find($runId)['status'])->toBe('paused')
        ->and(pausedWaitRaceStepRows($runId))->toBe($before);
});

test('G5 R3 changing waits exhaust exactly three attempts and leave the run paused', function (): void {
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

test('R2 R10 a resume victim retries once with one committed event outbox and eager push', function (string $edge): void {
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
            if (! $failed && str_starts_with(strtolower($query->sql), 'insert into "swarm_durable_outbox"')) {
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

test('R10 generation changes and concurrency victims share one three-attempt budget', function (): void {
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

test('R2 a concurrency victim inside an application transaction belongs to the application', function (): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->beforeResume = fn () => throw pausedWaitRaceDeadlock();
    expect(fn () => DB::transaction(fn () => app(DurableSwarmManager::class)->resume($runId)))->toThrow(DeadlockException::class, 'Deadlock');
    expect($store->attempts)->toBe(1)->and($store->find($runId)['status'])->toBe('paused');
});

test('G6 a failure inside ordinary boundary persistence rolls back its checkpoint', function (string $swarm): void {
    $runId = $swarm::make()->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    $store = app(DurableRunStore::class);
    $before = null;
    $manager->beforeStepCheckpointForTesting(function () use ($runId, $store, &$before): void {
        $before = $store->find($runId);
    });
    $store->beforeCreate = fn () => throw new RuntimeException('inside createWait');
    $stepRows = pausedWaitRaceStepRows($runId);

    expect(fn () => (new AdvanceDurableSwarm($runId, 0))->handle($manager))->toThrow(RuntimeException::class, 'inside createWait');
    $after = $store->find($runId);
    expect($after['next_step_index'])->toBe($before['next_step_index'])
        ->and($after['status'])->toBe($before['status'])
        ->and($after['execution_token'])->toBe($before['execution_token'])
        ->and($store->waits($runId))->toBe([])
        ->and(pausedWaitRaceStepRows($runId))->toBe($stepRows);
})->with([DurableWaitAttributeSwarm::class, OrdinaryBoundaryHierarchicalSwarm::class]);

test('R5 a failure after cursor persistence before the boundary rolls the checkpoint back', function (): void {
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

test('G6 an entered ordinary boundary never inserts a continuation step entry', function (bool $eager): void {
    config()->set('swarm.durable.eager_dispatch', $eager);
    Queue::fake();
    $runId = DurableWaitAttributeSwarm::make()->dispatchDurable('task')->runId;
    $insertions = 0;
    DB::listen(function (QueryExecuted $query) use (&$insertions): void {
        if (str_starts_with(strtolower($query->sql), 'insert into "swarm_durable_outbox"')) {
            $insertions++;
        }
    });
    $rows = pausedWaitRaceStepRows($runId);
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    expect($insertions)->toBe(0)
        ->and(pausedWaitRaceStepRows($runId))->toBe($rows)
        ->and(app(DurableRunStore::class)->find($runId)['status'])->toBe('waiting');
})->with(['relay' => false, 'eager' => true]);

test('R11 a boundary outcome already present follows the ordinary enqueue path', function (): void {
    $context = RunContext::from('task');
    $context->mergeMetadata(['durable_wait_outcomes' => ['approval_received' => ['status' => 'signalled', 'payload' => true, 'timed_out' => false]]]);
    $runId = DurableWaitAttributeSwarm::make()->dispatchDurable($context)->runId;
    $before = pausedWaitRaceStepRows($runId);
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    expect(app(DurableRunStore::class)->find($runId)['status'])->toBe('pending')
        ->and(app(DurableRunStore::class)->waits($runId))->toBe([])
        ->and(pausedWaitRaceStepRows($runId))->toBe($before + 1);
});

test('R5 ordinary boundary notification failure at level zero propagates after commit', function (): void {
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

test('R5 nested ordinary effects wait for commit and report without discarding later callbacks', function (): void {
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

test('R5 rolling back an application checkpoint discards its ordinary boundary effects', function (): void {
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

test('R8 generation observation precedes the wait scan', function (): void {
    $runId = pausedWaitRaceRun();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    app(DurableRunStore::class)->resume($runId);
    $observed = array_find_key($queries, fn (string $sql): bool => str_starts_with($sql, 'select "wait_generation"'));
    $scanned = array_find_key($queries, fn (string $sql): bool => str_contains($sql, 'select "id" from "swarm_durable_waits"'));
    expect($observed)->not->toBeNull()->and($scanned)->not->toBeNull()->and($observed)->toBeLessThan($scanned);
});

test('G5 an observed open wait wins over a conservative generation mismatch', function (): void {
    $runId = pausedWaitRaceRun();
    $store = app(DurableRunStore::class);
    $store->createWait($runId, 'seen');
    $store->scan = fn (string $id) => DB::table('swarm_durable_runs')->where('run_id', $id)->increment('wait_generation');
    expect(app(DurableSwarmManager::class)->resume($runId)->status->value)->toBe('waiting')
        ->and($store->attempts)->toBe(1)->and($store->find($runId)['status'])->toBe('waiting');
});

test('R8 rolled back wait creation does not poison a later resume generation', function (): void {
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

test('R5 ordinary persistence victims retry the checkpoint without reexecuting the agent', function (string $swarm): void {
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
    $store->beforeCreate = function () use (&$persists): void {
        if (++$persists === 1) {
            throw pausedWaitRaceDeadlock();
        }
    };
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    expect($persists)->toBe(2)->and($calls)->toBe(1)
        ->and($store->waits($runId))->toHaveCount(1)->and($store->find($runId)['status'])->toBe('waiting');
    Event::assertDispatchedTimes(SwarmWaiting::class, 1);
})->with([DurableWaitAttributeSwarm::class, OrdinaryBoundaryHierarchicalSwarm::class]);

test('G3 the same-connection postcommit hook sees the boundary and rejects the next lease', function (string $swarm): void {
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
