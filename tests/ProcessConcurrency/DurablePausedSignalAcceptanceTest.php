<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSignalHandler;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\DecoratingDurableRunStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableWaitAttributeSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\FanoutCloser\FanoutCloserWorker;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\PausedSignal\HookedResumeDurableRunStore;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\LockBarrier;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\ResumeOpenWaitWorker;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

/**
 * Accepting a signal delivered to a paused durable run (#601), on the real engines.
 *
 * The scenario tests run the paused-acceptance behaviour in-process on MySQL and
 * Postgres (tests/Feature covers it on SQLite). The race tests run two workers through
 * the process concurrency driver and use LockBarrier to observe, from the engine's
 * lock-wait view, that a worker is blocked on the other's lock before letting it go on.
 *
 * Acceptance takes the wait row (its closing UPDATE), then the run row; resume() takes
 * the open wait rows (a shared locking scan), then the run row. Neither holds the run
 * while asking for a wait, so the two never deadlock: R1 and R2 prove both orders. R5
 * proves the documented exception — an application transaction that already holds the
 * run (here through its own signal's foreign-key check) and then resumes — aborts one
 * side cleanly on MySQL, and a retry of the aborted side converges.
 *
 * Worker closures are built by free functions so they serialize to the child process,
 * which runs them against bare testbench: only autoloaded classes are referenced.
 */
// Tagged `skip-locked-real-db`: it needs a shared MySQL/Postgres connection reachable from
// the child processes, so the CI lane `test:process-concurrency:ci` excludes it and the
// real-DB workflow (`test:process-concurrency:real-db`) runs it.
pest()->group('process-concurrency', 'skip-locked-real-db');

function pausedSignalRealDriverSupported(): bool
{
    return ! in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true);
}

function pausedSignalRealBarrierDir(): string
{
    $dir = sys_get_temp_dir().'/laravel-swarm-paused-signal-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    pausedSignalRealBarrierDirs($dir);

    return $dir;
}

/**
 * @return array<int, string>
 */
function pausedSignalRealBarrierDirs(?string $add = null, bool $clear = false): array
{
    static $dirs = [];

    if ($add !== null) {
        $dirs[] = $add;
    }

    $current = $dirs;

    if ($clear) {
        $dirs = [];
    }

    return $current;
}

/**
 * Fake agent outputs that each take longer than a second: on MySQL a step that starts
 * and finishes within one second trips the pre-existing history-lease bug (release
 * finding MYSQL-LEASE), which this file is not about.
 *
 * @param  array<int, string>  $outputs
 */
function pausedSignalRealSlowOutputs(array $outputs): Closure
{
    return function () use (&$outputs): string {
        usleep(1_100_000);

        return (string) array_shift($outputs);
    };
}

/**
 * A pending sequential run with one open wait named `approval`, paused. No step runs, so
 * the wait is the only reason it waits.
 */
function pausedSignalRealPausedRun(): string
{
    $runId = FakeSequentialSwarm::make()->dispatchDurable('paused-signal-real-db')->runId;
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'approval', 'Approval');
    $manager->pause($runId);

    return $runId;
}

function pausedSignalRealRunStatus(string $runId): ?string
{
    return DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status');
}

function pausedSignalRealWait(string $runId, string $name = 'approval'): object
{
    return DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', $name)->orderByDesc('id')->first();
}

function pausedSignalRealStepRows(string $runId): int
{
    return DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'step')->count();
}

function pausedSignalRealSignalId(string $runId, string $key): ?int
{
    $id = DB::table('swarm_durable_signals')->where('run_id', $runId)->where('idempotency_key', $key)->value('id');

    return $id === null ? null : (int) $id;
}

/**
 * The signal id the run's context records for the wait.
 */
function pausedSignalRealContextSignalId(string $runId, string $name = 'approval'): ?int
{
    $id = app(ContextStore::class)->find($runId)['metadata']['durable_signals'][$name]['signal_id'] ?? null;

    return $id === null ? null : (int) $id;
}

/**
 * R1's acceptor: signals the paused run through a store that stops after the wait's
 * closing UPDATE and before the acceptance's first run-lock request, until the resumer
 * is observed blocked on this worker's lock.
 */
function pausedSignalRealHookedAcceptor(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        $store = FanoutCloserWorker::bootHooked();
        config()->set('swarm.durable.eager_dispatch', false);
        $me = LockBarrier::connectionId();
        $contended = false;
        $locks = null;
        $store->beforeRunLock = static function () use ($store, $dir, $me, &$contended, &$locks): void {
            $store->beforeRunLock = null;
            LockBarrier::signal($dir, 'acceptor-holds-wait');
            $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'resumer-id'), $me);

            if (! $contended) {
                $locks = LockBarrier::lockWaitSnapshot();
            }
        };

        try {
            $result = app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'r1');
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e), 'contended' => $contended];
        }

        return array_filter([
            'outcome' => $result->accepted ? 'accepted' : $result->status,
            'contended' => $contended,
            'locks' => $locks,
        ], fn (mixed $value): bool => $value !== null);
    };
}

/**
 * The full manager resume (its own transaction), started once the acceptor holds the
 * wait row.
 */
function pausedSignalRealResumer(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        ResumeOpenWaitWorker::boot();
        config()->set('swarm.durable.eager_dispatch', false);
        LockBarrier::signal($dir, 'resumer-id', (string) LockBarrier::connectionId());
        LockBarrier::await($dir, 'acceptor-holds-wait');

        try {
            return ['outcome' => app(DurableSwarmManager::class)->resume($runId)->status->value];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

/**
 * R2's resumer: resumes through a store that stops right after resume()'s shared scan of
 * the open waits, until the acceptor is observed blocked on this worker's lock.
 */
function pausedSignalRealHookedResumer(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        ResumeOpenWaitWorker::boot();
        config()->set('swarm.durable.eager_dispatch', false);
        $store = app(HookedResumeDurableRunStore::class);
        app()->instance(DurableRunStore::class, $store);
        app()->forgetInstance(DurableSwarmManager::class);
        $me = LockBarrier::connectionId();
        $contended = false;
        $locks = null;
        $store->afterResumeWaitScan = static function () use ($dir, $me, &$contended, &$locks): void {
            LockBarrier::signal($dir, 'resumer-holds-waits');
            $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'acceptor-id'), $me);

            if (! $contended) {
                $locks = LockBarrier::lockWaitSnapshot();
            }
        };

        try {
            $status = app(DurableSwarmManager::class)->resume($runId)->status->value;
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e), 'contended' => $contended];
        }

        return array_filter(['outcome' => $status, 'contended' => $contended, 'locks' => $locks], fn (mixed $value): bool => $value !== null);
    };
}

/**
 * R2's acceptor: signals the paused run once the resumer holds the open wait rows.
 */
function pausedSignalRealAcceptor(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        ResumeOpenWaitWorker::boot();
        config()->set('swarm.durable.eager_dispatch', false);
        LockBarrier::signal($dir, 'acceptor-id', (string) LockBarrier::connectionId());
        LockBarrier::await($dir, 'resumer-holds-waits');

        try {
            $result = app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'r2');
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => $result->accepted ? 'accepted' : $result->status];
    };
}

/**
 * R4: a worker killed partway through accepting a paused run's signal, after the signal
 * row is written and before the transaction commits.
 */
function pausedSignalRealKilledSender(string $runId): Closure
{
    return static function () use ($runId): string {
        $store = FanoutCloserWorker::bootHooked();
        $store->beforeSync = static function (): void {
            posix_kill(getmypid(), SIGKILL);
        };
        app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'key-1');

        return 'not killed';
    };
}

/**
 * R5's application: inside one application transaction, signals the paused run (recorded,
 * not accepted: it is inside a transaction; its insert's foreign-key check holds a shared
 * lock on the run row), lets the other sender run, then resumes the run.
 */
function pausedSignalRealTransactionalOperator(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        ResumeOpenWaitWorker::boot();
        config()->set('swarm.durable.eager_dispatch', false);
        $me = LockBarrier::connectionId();
        $manager = app(DurableSwarmManager::class);
        $isMysql = DB::connection()->getDriverName() === 'mysql';
        $signal = null;

        DB::beginTransaction();

        try {
            $signal = $manager->signal($runId, 'approval', ['by' => 'application'], 'key-a');
            LockBarrier::signal($dir, 'operator-signalled');
            $sender = (int) LockBarrier::await($dir, 'sender-id');

            if ($isMysql) {
                // The sender's acceptance has closed the wait and is waiting for the run
                // row, which this transaction's foreign-key check holds shared.
                if (! LockBarrier::awaitBlockedBy($sender, $me)) {
                    $locks = LockBarrier::lockWaitSnapshot();
                    DB::rollBack();

                    return ['outcome' => 'no-contention', 'locks' => $locks];
                }
            } else {
                // Postgres: the foreign-key KEY SHARE lock does not block the acceptance's
                // FOR NO KEY UPDATE, so the sender finishes first.
                LockBarrier::await($dir, 'sender-done');
            }

            $status = $manager->resume($runId)->status->value;
            DB::commit();

            return ['outcome' => 'committed', 'signal' => $signal->status, 'resume' => $status];
        } catch (Throwable $e) {
            try {
                DB::rollBack();
            } catch (Throwable) {
                // Already rolled back by the engine's deadlock abort.
            }

            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e), 'signal' => $signal?->status];
        }
    };
}

/**
 * R5's other sender: outside any transaction, signals the paused run once the
 * application's transaction has recorded its own signal.
 */
function pausedSignalRealOutsideSender(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        ResumeOpenWaitWorker::boot();
        config()->set('swarm.durable.eager_dispatch', false);
        LockBarrier::await($dir, 'operator-signalled');
        LockBarrier::signal($dir, 'sender-id', (string) LockBarrier::connectionId());

        try {
            $result = app(DurableSwarmManager::class)->signal($runId, 'approval', ['by' => 'sender'], 'key-b');
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        } finally {
            LockBarrier::signal($dir, 'sender-done');
        }

        return ['outcome' => $result->accepted ? 'accepted' : $result->status];
    };
}

beforeEach(function (): void {
    if (! pausedSignalRealDriverSupported()) {
        $this->markTestSkipped(
            'The paused-signal acceptance test requires a shared database engine reachable '
            .'from child processes (mysql/pgsql). Current driver: '.DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');
    config()->set('swarm.durable.eager_dispatch', false);
    config()->set('queue.default', 'null');

    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
});

afterEach(function (): void {
    foreach (pausedSignalRealBarrierDirs(clear: true) as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

// ---------------------------------------------------------------------------------------
// Scenarios on the real engine (the Feature tests cover the same on SQLite).
// ---------------------------------------------------------------------------------------

test('a signal delivered while paused is accepted, the run stays paused, and resume continues it on the real engine', function (): void {
    FakeResearcher::fake(pausedSignalRealSlowOutputs(['research-out']));
    FakeWriter::fake(pausedSignalRealSlowOutputs(['writer-out']));
    $runId = DurableWaitAttributeSwarm::make()->dispatchDurable('wait-task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $manager->pause($runId);
    $stepRows = pausedSignalRealStepRows($runId);

    $accepted = $manager->signal($runId, 'approval_received', ['approved' => true], 'paused');
    $signalId = pausedSignalRealSignalId($runId, 'paused');

    expect($accepted->accepted)->toBeTrue()
        ->and(pausedSignalRealRunStatus($runId))->toBe('paused')
        ->and(pausedSignalRealWait($runId, 'approval_received')->status)->toBe('signalled')
        ->and((int) pausedSignalRealWait($runId, 'approval_received')->signal_id)->toBe($signalId)
        ->and(pausedSignalRealContextSignalId($runId, 'approval_received'))->toBe($signalId)
        ->and(DB::table('swarm_durable_signals')->where('id', $signalId)->value('status'))->toBe('consumed')
        ->and(pausedSignalRealStepRows($runId))->toBe($stepRows);

    expect($manager->resume($runId)->isWaiting())->toBeFalse()
        ->and(pausedSignalRealRunStatus($runId))->toBe('pending')
        ->and(pausedSignalRealStepRows($runId))->toBe($stepRows + 1);

    $again = $manager->signal($runId, 'approval_received', ['approved' => false], 'after-resume');

    expect($again->accepted)->toBeFalse()
        ->and($again->duplicate)->toBeFalse()
        ->and($again->status)->toBe('recorded')
        ->and(pausedSignalRealStepRows($runId))->toBe($stepRows + 1)
        ->and((int) pausedSignalRealWait($runId, 'approval_received')->signal_id)->toBe($signalId);

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    expect(pausedSignalRealRunStatus($runId))->toBe('completed');
});

test('a fresh delivery while paused satisfies the wait, never an earlier same-name signal, on the real engine', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('fresh-real-db')->runId;
    $manager = app(DurableSwarmManager::class);
    $manager->signal($runId, 'approval', ['n' => 1], 'early');
    $manager->wait($runId, 'approval');
    $manager->pause($runId);

    expect($manager->signal($runId, 'approval', ['n' => 2], 'fresh')->accepted)->toBeTrue()
        ->and((int) pausedSignalRealWait($runId)->signal_id)->toBe(pausedSignalRealSignalId($runId, 'fresh'))
        ->and(DB::table('swarm_durable_signals')->where('idempotency_key', 'early')->where('run_id', $runId)->value('status'))->toBe('recorded');
});

test('the wait summary is cleared when a paused run\'s last wait closes, on the real engine', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('summary-real-db')->runId;
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'approval', 'Approval', 60);
    $manager->pause($runId);
    $manager->signal($runId, 'approval', null, 'summary');

    $run = DB::table('swarm_durable_runs')->where('run_id', $runId)->first();

    expect($run->status)->toBe('paused')
        ->and($run->wait_reason)->toBeNull()
        ->and($run->waiting_since)->toBeNull()
        ->and($run->wait_timeout_at)->toBeNull();
});

test('inside an application transaction a paused run\'s signal is recorded, not accepted, on the real engine', function (): void {
    $runId = pausedSignalRealPausedRun();

    $result = DB::transaction(fn () => app(DurableSwarmManager::class)->signal($runId, 'approval', null, 'in-transaction'));

    expect($result->accepted)->toBeFalse()
        ->and($result->status)->toBe('recorded')
        ->and(pausedSignalRealWait($runId)->status)->toBe('waiting')
        ->and(app(DurableSwarmManager::class)->resume($runId)->isWaiting())->toBeTrue();
});

test('a wait signalled while paused is neither a timeout candidate nor releasable as timed out, on the real engine', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('timeout-real-db')->runId;
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'a', 'Timed', 60);
    $manager->wait($runId, 'b', 'Open', 7200);
    $manager->pause($runId);
    $manager->signal($runId, 'a', null, 'a-key');
    $manager->resume($runId);

    expect(pausedSignalRealRunStatus($runId))->toBe('waiting');

    $this->travel(120)->seconds();

    expect(collect(app(DurableRunStore::class)->recoverableTimedOutWaits($runId))->pluck('wait_name')->all())->toBe([])
        ->and(app(DurableRunStore::class)->releaseTimedOutWait($runId, 'a'))->toBeFalse()
        ->and(pausedSignalRealWait($runId, 'a')->status)->toBe('signalled');
});

test('a store without the atomic acceptance capability records a paused run\'s signal, on the real engine', function (): void {
    $runId = pausedSignalRealPausedRun();
    $handler = app()->makeWith(DurableSignalHandler::class, ['durableRuns' => new DecoratingDurableRunStore(app(DurableRunStore::class))]);

    expect($handler->signal($runId, 'approval', null, 'fallback')->accepted)->toBeFalse()
        ->and(pausedSignalRealWait($runId)->status)->toBe('waiting');
});

// ---------------------------------------------------------------------------------------
// Races, barrier-controlled.
// ---------------------------------------------------------------------------------------

test('R1: resume waits for an acceptance that holds the paused run\'s wait, then continues the run once', function (): void {
    $runId = pausedSignalRealPausedRun();
    $dir = pausedSignalRealBarrierDir();
    $stepRows = pausedSignalRealStepRows($runId);

    [$acceptor, $resumer] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        pausedSignalRealHookedAcceptor($runId, $dir),
        pausedSignalRealResumer($runId, $dir),
    ]);

    $signalId = pausedSignalRealSignalId($runId, 'r1');

    expect($acceptor)->toBe(['outcome' => 'accepted', 'contended' => true], json_encode($acceptor))
        ->and($resumer)->toBe(['outcome' => 'resumed'], json_encode($resumer))
        ->and(pausedSignalRealRunStatus($runId))->toBe('pending')
        ->and(pausedSignalRealWait($runId)->status)->toBe('signalled')
        ->and((int) pausedSignalRealWait($runId)->signal_id)->toBe($signalId)
        ->and(pausedSignalRealContextSignalId($runId))->toBe($signalId)
        ->and(pausedSignalRealStepRows($runId))->toBe($stepRows + 1);
});

test('R2: an acceptance waits for a resume that holds the paused run\'s open waits, then continues the run once', function (): void {
    $runId = pausedSignalRealPausedRun();
    $dir = pausedSignalRealBarrierDir();
    $stepRows = pausedSignalRealStepRows($runId);

    [$resumer, $acceptor] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        pausedSignalRealHookedResumer($runId, $dir),
        pausedSignalRealAcceptor($runId, $dir),
    ]);

    $signalId = pausedSignalRealSignalId($runId, 'r2');

    expect($resumer)->toBe(['outcome' => 'waiting', 'contended' => true], json_encode($resumer))
        ->and($acceptor)->toBe(['outcome' => 'accepted'], json_encode($acceptor))
        ->and(pausedSignalRealRunStatus($runId))->toBe('pending')
        ->and(pausedSignalRealWait($runId)->status)->toBe('signalled')
        ->and((int) pausedSignalRealWait($runId)->signal_id)->toBe($signalId)
        ->and(pausedSignalRealContextSignalId($runId))->toBe($signalId)
        ->and(pausedSignalRealStepRows($runId))->toBe($stepRows + 1);
});

test('R4: a worker killed partway through accepting a paused run\'s signal leaves nothing behind, and the same key is accepted once', function (): void {
    if (! function_exists('posix_kill') || ! defined('SIGKILL')) {
        $this->markTestSkipped('Requires POSIX SIGKILL.');
    }

    $runId = pausedSignalRealPausedRun();
    $died = null;

    try {
        $this->app->make(ConcurrencyManager::class)->driver('process')->run([pausedSignalRealKilledSender($runId)]);
    } catch (Throwable $e) {
        $died = $e->getMessage();
    }

    // macOS reports the SIGKILL as signal 9, Linux as exit code 137.
    expect($died)->toMatch('/signal "9"|exit code \\[137\\]/')
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(0)
        ->and(pausedSignalRealWait($runId)->status)->toBe('waiting')
        ->and(pausedSignalRealRunStatus($runId))->toBe('paused');

    $manager = app(DurableSwarmManager::class);
    $resent = $manager->signal($runId, 'approval', ['approved' => true], 'key-1');
    $again = $manager->signal($runId, 'approval', ['approved' => true], 'key-1');

    expect($resent->accepted)->toBeTrue()
        ->and($again->duplicate)->toBeTrue()
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(1)
        ->and(pausedSignalRealRunStatus($runId))->toBe('paused')
        ->and($manager->resume($runId)->isWaiting())->toBeFalse()
        ->and(pausedSignalRealRunStatus($runId))->toBe('pending');
});

test('R5: an application transaction that signals and resumes a paused run while another process signals it aborts one side cleanly, and a retry converges', function (): void {
    // Documented family (operator decision, #601; release finding
    // PAUSED-SIGNAL-APP-TX-DEADLOCK): on MySQL the application's transaction holds the run
    // row shared through its own signal's foreign-key check, the other sender's acceptance
    // holds the wait and waits for the run row, and the application's resume() then waits
    // for the wait. The engine aborts one side; nothing is half-written, and retrying the
    // aborted side's whole operation converges. Postgres has no cycle here.
    $runId = pausedSignalRealPausedRun();
    $dir = pausedSignalRealBarrierDir();
    $stepRows = pausedSignalRealStepRows($runId);
    $manager = app(DurableSwarmManager::class);

    [$operator, $sender] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        pausedSignalRealTransactionalOperator($runId, $dir),
        pausedSignalRealOutsideSender($runId, $dir),
    ]);

    expect($operator['outcome'])->not->toBe('no-contention', json_encode($operator));

    if (DB::connection()->getDriverName() === 'mysql') {
        $outcomes = [$operator['outcome'], $sender['outcome']];
        sort($outcomes);

        expect($outcomes)->toBe(['aborted', $operator['outcome'] === 'aborted' ? 'accepted' : 'committed'], json_encode([$operator, $sender]));

        if ($operator['outcome'] === 'aborted') {
            expect($operator['failure'])->toBe('sqlstate:40001', json_encode($operator))
                // Nothing of the application's transaction survives; the sender's
                // acceptance is committed and the run is still paused.
                ->and(pausedSignalRealSignalId($runId, 'key-a'))->toBeNull()
                ->and(pausedSignalRealWait($runId)->status)->toBe('signalled')
                ->and(pausedSignalRealRunStatus($runId))->toBe('paused');

            // Retry the application's whole transaction.
            DB::transaction(function () use ($manager, $runId): void {
                $manager->signal($runId, 'approval', ['by' => 'application'], 'key-a');
                $manager->resume($runId);
            });
        } else {
            expect($sender['failure'])->toBe('sqlstate:40001', json_encode($sender))
                ->and($operator)->toMatchArray(['signal' => 'recorded', 'resume' => 'waiting'])
                ->and(pausedSignalRealSignalId($runId, 'key-b'))->toBeNull()
                ->and(pausedSignalRealWait($runId)->status)->toBe('waiting')
                ->and(pausedSignalRealRunStatus($runId))->toBe('waiting');

            // Retry the sender's signal, outside any transaction as before.
            expect($manager->signal($runId, 'approval', ['by' => 'sender'], 'key-b')->accepted)->toBeTrue();
        }
    } else {
        expect($sender)->toBe(['outcome' => 'accepted'], json_encode($sender))
            ->and($operator)->toBe(['outcome' => 'committed', 'signal' => 'recorded', 'resume' => 'resumed'], json_encode($operator));
    }

    expect(pausedSignalRealRunStatus($runId))->toBe('pending')
        ->and(pausedSignalRealWait($runId)->status)->toBe('signalled')
        ->and(pausedSignalRealStepRows($runId))->toBe($stepRows + 1);
});
