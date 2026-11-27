<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmSignalled;
use BuiltByBerry\LaravelSwarm\Exceptions\DurableWaitSetChangedException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSignalHandler;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\DecoratingDurableRunStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * #635: every wait carries the wait_generation that created it, and a closer whose
 * transaction cannot see every stamped wait of the run refuses to release it.
 *
 * SQLite cannot hold an older snapshot across another writer's commit, so these tests
 * stand a hidden creation in by advancing the run's wait_generation without a visible
 * stamped wait: what a MySQL REPEATABLE READ transaction sees when a wait was created
 * after its snapshot. The real snapshot cases are in
 * tests/ProcessConcurrency/DurableJoinOpenWaitTest.php.
 */
beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

/**
 * @param  array<int, string>  $waits
 */
function staleScanRun(array $waits = ['approval'], ?int $timeoutSeconds = null): string
{
    $runId = FakeSequentialSwarm::make()->dispatchDurable('stale-scan-task')->runId;

    foreach ($waits as $wait) {
        app(DurableSwarmManager::class)->wait($runId, $wait, timeoutSeconds: $timeoutSeconds);
    }

    return $runId;
}

/**
 * A creation this transaction cannot see: the generation advanced, no stamped wait.
 */
function staleScanHideCreation(string $runId): void
{
    DB::table('swarm_durable_runs')->where('run_id', $runId)->increment('wait_generation');
}

function staleScanUnhideCreation(string $runId): void
{
    DB::table('swarm_durable_runs')->where('run_id', $runId)->decrement('wait_generation');
}

/**
 * @return array<int, int|null>
 */
function staleScanStamps(string $runId): array
{
    return DB::table('swarm_durable_waits')->where('run_id', $runId)->orderBy('id')->pluck('creation_generation')
        ->map(fn (mixed $stamp): ?int => $stamp === null ? null : (int) $stamp)->all();
}

function staleScanStatus(string $runId): ?string
{
    return DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status');
}

function staleScanWaitStatus(string $runId, string $name): ?string
{
    return DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', $name)->value('status');
}

function staleScanStepRows(string $runId): int
{
    return DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'step')->count();
}

function staleScanAppTable(): void
{
    Schema::create('stale_scan_app_marks', function ($table): void {
        $table->id();
        $table->string('run_id');
    });
}

test('createWait stamps each wait with the generation that created it, per run', function (): void {
    $runId = staleScanRun(['approval', 'legal', 'second']);
    $other = staleScanRun(['approval']);

    expect(staleScanStamps($runId))->toBe([1, 2, 3])
        ->and((int) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('wait_generation'))->toBe(3)
        ->and(staleScanStamps($other))->toBe([1]);
});

test('a rolled-back wait creation leaves neither its generation nor its stamp, and the last close still releases', function (): void {
    $runId = staleScanRun(['approval']);

    try {
        DB::transaction(function () use ($runId): void {
            app(DurableRunStore::class)->createWait($runId, 'abandoned');

            throw new RuntimeException('application rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(staleScanStamps($runId))->toBe([1])
        ->and((int) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('wait_generation'))->toBe(1)
        ->and(app(DurableSwarmManager::class)->signal($runId, 'approval')->accepted)->toBeTrue()
        ->and(staleScanStatus($runId))->toBe('pending');
});

/**
 * A run upgraded with its waits open: no stamps, generation zero.
 */
function staleScanMakeLegacy(string $runId): void
{
    DB::table('swarm_durable_waits')->where('run_id', $runId)->update(['creation_generation' => null]);
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['wait_generation' => 0]);
}

test('a run whose only wait predates the stamp is released when it closes', function (): void {
    $legacyOnly = staleScanRun(['approval']);
    staleScanMakeLegacy($legacyOnly);

    expect(app(DurableSwarmManager::class)->signal($legacyOnly, 'approval')->accepted)->toBeTrue()
        ->and(staleScanStatus($legacyOnly))->toBe('pending');
});

test('a legacy wait and a wait stamped after the upgrade release the run once, when the last closes', function (): void {
    $mixed = staleScanRun(['approval']);
    staleScanMakeLegacy($mixed);
    app(DurableSwarmManager::class)->wait($mixed, 'second');
    $steps = staleScanStepRows($mixed);

    expect(staleScanStamps($mixed))->toBe([null, 1])
        ->and(app(DurableSwarmManager::class)->signal($mixed, 'approval')->accepted)->toBeTrue()
        ->and(staleScanStatus($mixed))->toBe('waiting')
        ->and(app(DurableSwarmManager::class)->signal($mixed, 'second')->accepted)->toBeTrue()
        ->and(staleScanStatus($mixed))->toBe('pending')
        ->and(staleScanStepRows($mixed))->toBe($steps + 1);
});

test('a legacy wait does not stand in for a stamped wait the transaction cannot see', function (): void {
    $hidden = staleScanRun(['legacy', 'approval']);
    DB::table('swarm_durable_waits')->where('run_id', $hidden)->where('name', 'legacy')->update(['creation_generation' => null, 'status' => 'signalled']);
    DB::table('swarm_durable_runs')->where('run_id', $hidden)->update(['wait_generation' => 1]);
    DB::table('swarm_durable_waits')->where('run_id', $hidden)->where('name', 'approval')->update(['creation_generation' => 1]);
    staleScanHideCreation($hidden);

    expect(fn () => app(DurableRunStore::class)->releaseWaitWithOutcome($hidden, 'approval', 'child_completed', ['status' => 'completed']))
        ->toThrow(DurableWaitSetChangedException::class);
});

test('every wait closer refuses the last visible wait when a creation is hidden, and writes nothing', function (string $closer, string $status): void {
    $runId = staleScanRun(['approval'], timeoutSeconds: $closer === 'timeout' ? -1 : null);
    $store = app(DurableRunStore::class);

    if ($status === 'paused') {
        DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['status' => 'paused']);
    }

    $signalId = $closer === 'recorded-signal' ? (int) $store->recordSignal($runId, 'approval')['id'] : null;
    staleScanHideCreation($runId);

    $close = match ($closer) {
        'accept-signal' => fn () => $store->acceptSignal($runId, 'approval', null, 'key-1'),
        'recorded-signal' => fn () => $store->releaseWaitWithSignal($runId, 'approval', (int) $signalId),
        'outcome' => fn () => $store->releaseWaitWithOutcome($runId, 'approval', 'child_completed', ['status' => 'completed']),
        'timeout' => fn () => $store->releaseTimedOutWait($runId, 'approval'),
    };

    expect($close)->toThrow(DurableWaitSetChangedException::class);
    expect(staleScanWaitStatus($runId, 'approval'))->toBe('waiting')
        ->and(staleScanStatus($runId))->toBe($status)
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->where('idempotency_key', 'key-1')->count())->toBe(0);

    // Once the transaction can see every creation, the same close goes through.
    staleScanUnhideCreation($runId);

    expect($close())->not->toBeFalse()
        ->and(staleScanWaitStatus($runId, 'approval'))->not->toBe('waiting')
        ->and(staleScanStatus($runId))->toBe($status === 'paused' ? 'paused' : 'pending');
})->with([
    'accept-signal' => ['accept-signal'],
    'recorded-signal' => ['recorded-signal'],
    'outcome' => ['outcome'],
    'timeout' => ['timeout'],
])->with(['waiting', 'paused']);

test('a hidden creation does not stop a close on a run that is neither waiting nor paused', function (string $status): void {
    $runId = staleScanRun(['approval']);
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['status' => $status]);
    staleScanHideCreation($runId);

    expect(app(DurableRunStore::class)->releaseWaitWithOutcome($runId, 'approval', 'child_completed', ['status' => 'completed']))->toBeTrue()
        ->and(staleScanWaitStatus($runId, 'approval'))->toBe('child_completed')
        ->and(staleScanStatus($runId))->toBe($status);
})->with(['pending', 'running']);

test('the refusal names the run and how many of its created waits the transaction saw', function (): void {
    $runId = staleScanRun(['approval']);
    staleScanHideCreation($runId);

    expect(fn () => app(DurableRunStore::class)->releaseWaitWithOutcome($runId, 'approval', 'child_completed', ['status' => 'completed']))
        ->toThrow(DurableWaitSetChangedException::class, "Durable run [{$runId}] was not moved: this transaction sees 1 of the 2 waits created on it, so a wait created since it first read may still be open. Retry from a fresh transaction.");
});

test('a refused child outcome does not stop swarm:recover; the other parent gets its outcome and the refused one is retried later', function (): void {
    $store = app(DurableRunStore::class);
    $parents = [];

    foreach (['refused', 'healthy'] as $label) {
        $parentId = staleScanRun([]);
        $childId = 'child-'.$label.'-'.Str::random(6);
        app(DurableSwarmManager::class)->wait($parentId, "child:{$childId}");
        $store->createChildRun($parentId, $childId, FakeSequentialSwarm::class, "child:{$childId}", []);
        DB::table('swarm_durable_child_runs')->where('child_run_id', $childId)->update(['status' => 'completed', 'updated_at' => now()->subMinutes($label === 'refused' ? 2 : 1)]);
        $parents[$label] = [$parentId, "child:{$childId}"];
    }

    staleScanHideCreation($parents['refused'][0]);

    app(DurableSwarmManager::class)->recover();

    expect(staleScanWaitStatus($parents['refused'][0], $parents['refused'][1]))->toBe('waiting')
        ->and(staleScanStatus($parents['refused'][0]))->toBe('waiting')
        ->and(staleScanWaitStatus($parents['healthy'][0], $parents['healthy'][1]))->toBe('child_completed')
        ->and(staleScanStatus($parents['healthy'][0]))->toBe('pending');

    // Once the creation is visible, the next sweep delivers the refused outcome.
    staleScanUnhideCreation($parents['refused'][0]);
    app(DurableSwarmManager::class)->recover();

    expect(staleScanWaitStatus($parents['refused'][0], $parents['refused'][1]))->toBe('child_completed')
        ->and(staleScanStatus($parents['refused'][0]))->toBe('pending');
});

test('another run\'s stamped waits do not cover a creation hidden on this run', function (): void {
    staleScanRun(['first', 'second', 'third']);
    $runId = staleScanRun(['approval']);
    staleScanHideCreation($runId);

    expect(fn () => app(DurableRunStore::class)->releaseWaitWithOutcome($runId, 'approval', 'child_completed', ['status' => 'completed']))
        ->toThrow(DurableWaitSetChangedException::class);
    expect(staleScanWaitStatus($runId, 'approval'))->toBe('waiting');
});

test('a refused signal inside an application transaction leaves nothing of its acceptance when the application commits; the same key is accepted later', function (): void {
    staleScanAppTable();
    Event::fake([SwarmSignalled::class]);
    $runId = staleScanRun(['approval']);
    $steps = staleScanStepRows($runId);
    $history = app(RunHistoryStore::class)->find($runId);
    staleScanHideCreation($runId);
    $refused = null;

    DB::transaction(function () use ($runId, &$refused): void {
        try {
            app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'key-1');
        } catch (SwarmException $exception) {
            $refused = $exception;
        }

        DB::table('stale_scan_app_marks')->insert(['run_id' => $runId]);
    });

    expect($refused)->toBeInstanceOf(SwarmException::class)
        ->and($refused->getPrevious())->toBeInstanceOf(DurableWaitSetChangedException::class)
        ->and($refused->getMessage())->toBe("Durable signal [approval] for run [{$runId}] could not be accepted: a wait was opened on the run after this database transaction first read it. Nothing was recorded; send the signal again with the same idempotency key after this transaction ends.")
        ->and(DB::table('stale_scan_app_marks')->where('run_id', $runId)->count())->toBe(1)
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(0)
        ->and(staleScanWaitStatus($runId, 'approval'))->toBe('waiting')
        ->and(staleScanStatus($runId))->toBe('waiting')
        ->and(app(ContextStore::class)->find($runId)['metadata']['durable_signals'] ?? [])->toBe([])
        ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe($history['status'])
        ->and(staleScanStepRows($runId))->toBe($steps);
    Event::assertNotDispatched(SwarmSignalled::class);

    staleScanUnhideCreation($runId);

    $result = app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'key-1');

    expect($result->accepted)->toBeTrue()
        ->and($result->duplicate)->toBeFalse()
        ->and(staleScanStatus($runId))->toBe('pending')
        ->and(staleScanStepRows($runId))->toBe($steps + 1);
});

test('a refused signal outside a transaction surfaces the same SwarmException and records nothing', function (): void {
    $runId = staleScanRun(['approval']);
    staleScanHideCreation($runId);

    expect(fn () => app(DurableSwarmManager::class)->signal($runId, 'approval', null, 'key-1'))
        ->toThrow(SwarmException::class, 'send the signal again with the same idempotency key');
    expect(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(0)
        ->and(staleScanWaitStatus($runId, 'approval'))->toBe('waiting');
});

test('a store without atomic acceptance keeps the recorded signal on a refusal and asks for a new key', function (): void {
    staleScanAppTable();
    $runId = staleScanRun(['approval']);
    $handler = app()->makeWith(DurableSignalHandler::class, ['durableRuns' => new DecoratingDurableRunStore(app(DurableRunStore::class))]);
    staleScanHideCreation($runId);
    $refused = null;

    DB::transaction(function () use ($handler, $runId, &$refused): void {
        try {
            $handler->signal($runId, 'approval', null, 'key-1');
        } catch (SwarmException $exception) {
            $refused = $exception;
        }

        DB::table('stale_scan_app_marks')->insert(['run_id' => $runId]);
    });

    expect($refused?->getPrevious())->toBeInstanceOf(DurableWaitSetChangedException::class)
        ->and($refused?->getMessage())->toBe("Durable signal [approval] for run [{$runId}] could not be accepted: a wait was opened on the run after this database transaction first read it. The signal was recorded but not accepted; after this transaction ends, send it again with a new idempotency key, or roll this transaction back to discard the recorded signal.")
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->where('idempotency_key', 'key-1')->count())->toBe(1)
        ->and(staleScanWaitStatus($runId, 'approval'))->toBe('waiting');

    staleScanUnhideCreation($runId);

    // The same key is a duplicate and closes nothing; a new key is accepted.
    $duplicate = $handler->signal($runId, 'approval', null, 'key-1');
    expect($duplicate->duplicate)->toBeTrue()
        ->and($duplicate->accepted)->toBeFalse()
        ->and(staleScanWaitStatus($runId, 'approval'))->toBe('waiting');

    expect($handler->signal($runId, 'approval', null, 'key-2')->accepted)->toBeTrue()
        ->and(staleScanStatus($runId))->toBe('pending');
});

test('the store is not ready without the wait creation_generation column', function (): void {
    Schema::table('swarm_durable_waits', function ($table): void {
        $table->dropColumn('creation_generation');
    });

    expect(fn () => app(DurableRunStore::class)->assertReady())
        ->toThrow(SwarmException::class, 'Database-backed durable swarms require the [swarm_durable_waits] table for durable run inspection.');
});
