<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\FanoutCloser\FanoutCloserWorker;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\LockBarrier;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

/**
 * A signal whose acceptance aborts — here on a genuine lock-wait timeout from the engine —
 * leaves no signal row behind, so sending it again with the same idempotency key accepts
 * it exactly once (#611). Covered at top level and inside an application transaction that
 * catches the failure and commits; and two waits of one run receiving the same key at once
 * accept it once.
 *
 * Worker closures are built by free functions and only reference autoloaded classes, so
 * they run in a child process against bare testbench (see DurableFanoutCloserLockOrderTest).
 */
// Tagged `skip-locked-real-db`: it requires a shared MySQL/Postgres connection.
pest()->group('process-concurrency', 'skip-locked-real-db');

function signalAbortDir(): string
{
    $dir = sys_get_temp_dir().'/laravel-swarm-signal-abort-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    signalAbortDirs($dir);

    return $dir;
}

/**
 * @return array<int, string>
 */
function signalAbortDirs(?string $add = null, bool $clear = false): array
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
 * Holds the run's context row write-locked — not the run row — so a signal's acceptance
 * gets through its wait, run and signal writes and then times out on the context write.
 */
function signalAbortHoldingContext(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        FanoutCloserWorker::bootHooked();
        DB::beginTransaction();
        DB::table('swarm_contexts')->where('run_id', $runId)->lockForUpdate()->first();
        LockBarrier::signal($dir, 'context-held');
        LockBarrier::await($dir, 'first-attempt-done', 60.0);
        DB::rollBack();
        LockBarrier::signal($dir, 'context-released');

        return ['outcome' => 'released'];
    };
}

/**
 * Sends the signal three times with one idempotency key: the first while the context row
 * is held, under a one-second lock timeout, so its acceptance aborts on a driver error;
 * the second and third once the row is free. With $insideApplicationTransaction, the
 * first send runs inside an application transaction that also writes an unrelated row,
 * catches the failure, proves the transaction still usable, and commits.
 */
function signalAbortSignaller(string $runId, string $otherRunId, string $dir, bool $insideApplicationTransaction): Closure
{
    return static function () use ($runId, $otherRunId, $dir, $insideApplicationTransaction): array {
        FanoutCloserWorker::bootHooked();
        DB::statement(DB::connection()->getDriverName() === 'pgsql'
            ? "set lock_timeout = '1s'"
            : 'set session innodb_lock_wait_timeout = 1');
        LockBarrier::await($dir, 'context-held');
        $manager = app(DurableSwarmManager::class);
        $first = null;
        $usable = null;

        try {
            if ($insideApplicationTransaction) {
                DB::beginTransaction();
                $manager->updateDetails($otherRunId, ['written' => 'by-the-application']);
            }

            try {
                $manager->signal($runId, 'approval', ['approved' => true], 'key-1');
                $first = 'returned';
            } catch (Throwable $e) {
                $first = LockBarrier::failure($e);
            }

            if ($insideApplicationTransaction) {
                $usable = (int) DB::selectOne('select 1 as one')->one === 1;
                DB::commit();
            }
        } finally {
            LockBarrier::signal($dir, 'first-attempt-done');
        }

        LockBarrier::await($dir, 'context-released', 60.0);
        $second = $manager->signal($runId, 'approval', ['approved' => true], 'key-1');
        $third = $manager->signal($runId, 'approval', ['approved' => true], 'key-1');

        return [
            'first' => $first,
            'usable' => $usable,
            'second' => ['accepted' => $second->accepted, 'duplicate' => $second->duplicate],
            'third' => ['accepted' => $third->accepted, 'duplicate' => $third->duplicate],
        ];
    };
}

/**
 * Accepts a signal for wait `a` and, holding its wait, run and signal rows, waits until
 * the other sender is observed blocked on them or has finished.
 */
function signalAbortHoldingSender(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        $store = FanoutCloserWorker::bootHooked();
        $me = LockBarrier::connectionId();
        $contended = null;
        $store->beforeSync = static function () use ($dir, $me, &$contended, $store): void {
            $store->beforeSync = null;
            LockBarrier::signal($dir, 'first-holds', (string) $me);
            $deadline = microtime(true) + 15.0;

            while (microtime(true) < $deadline) {
                if (is_file($dir.'/second-done')) {
                    $contended = false;

                    return;
                }

                if (is_file($dir.'/second-started') && LockBarrier::isBlockedBy((int) file_get_contents($dir.'/second-started'), $me)) {
                    $contended = true;

                    return;
                }

                usleep(10_000);
            }
        };
        $result = app(DurableSwarmManager::class)->signal($runId, 'a', ['wait' => 'a'], 'shared-key');

        return ['accepted' => $result->accepted, 'duplicate' => $result->duplicate, 'contended' => $contended];
    };
}

function signalAbortSecondSender(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        FanoutCloserWorker::bootHooked();
        LockBarrier::await($dir, 'first-holds');
        // Marked before the send starts, not at a lock: its acceptance closes wait `b` with
        // an UPDATE and then asks for the run row, and on MySQL that UPDATE's scan of the
        // run's waits can already wait on the first sender's rows.
        LockBarrier::signal($dir, 'second-started', (string) LockBarrier::connectionId());

        try {
            $result = app(DurableSwarmManager::class)->signal($runId, 'b', ['wait' => 'b'], 'shared-key');
        } finally {
            LockBarrier::signal($dir, 'second-done');
        }

        return ['accepted' => $result->accepted, 'duplicate' => $result->duplicate];
    };
}

/**
 * Accepts a signal for one wait, pausing when it is about to lock the run row until the
 * other sender is there too, or is observed blocked on this one, or has finished — so
 * both acceptances are in flight before either asks for the run row.
 */
function signalAbortPairedSender(string $runId, string $wait, string $me, string $other, string $dir): Closure
{
    return static function () use ($runId, $wait, $me, $other, $dir): array {
        $store = FanoutCloserWorker::bootHooked();
        $connection = LockBarrier::connectionId();
        $paired = null;
        $store->beforeRunLock = static function () use ($dir, $me, $other, $connection, &$paired, $store): void {
            $store->beforeRunLock = null;
            LockBarrier::signal($dir, $me.'-at-run-lock', (string) $connection);
            LockBarrier::signal($dir, $me.'-id', (string) $connection);
            $deadline = microtime(true) + 15.0;

            while (microtime(true) < $deadline) {
                if (is_file($dir.'/'.$other.'-at-run-lock') || is_file($dir.'/'.$other.'-done')) {
                    $paired = 'both-in-flight';

                    return;
                }

                if (is_file($dir.'/'.$other.'-id') && LockBarrier::isBlockedBy((int) file_get_contents($dir.'/'.$other.'-id'), $connection)) {
                    $paired = 'other-blocked';

                    return;
                }

                usleep(10_000);
            }
        };
        LockBarrier::signal($dir, $me.'-id', (string) $connection);

        try {
            $result = app(DurableSwarmManager::class)->signal($runId, $wait, ['wait' => $wait], $wait.'-key');
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        } finally {
            LockBarrier::signal($dir, $me.'-done');
        }

        return ['outcome' => $result->accepted ? 'accepted' : 'refused', 'paired' => $paired];
    };
}

/**
 * Holds the run row write-locked until the other worker has finished its send.
 */
function signalAbortHoldingRun(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        FanoutCloserWorker::bootHooked();
        DB::beginTransaction();
        DB::table('swarm_durable_runs')->where('run_id', $runId)->lockForUpdate()->first();
        LockBarrier::signal($dir, 'run-held');
        LockBarrier::await($dir, 'repeat-done', 60.0);
        DB::rollBack();

        return ['outcome' => 'released'];
    };
}

/**
 * Sends a key already recorded for the run, under a one-second lock timeout, while the
 * run row is held by the other worker.
 */
function signalAbortRepeatSender(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        FanoutCloserWorker::bootHooked();
        DB::statement(DB::connection()->getDriverName() === 'pgsql'
            ? "set lock_timeout = '1s'"
            : 'set session innodb_lock_wait_timeout = 1');
        LockBarrier::await($dir, 'run-held');

        try {
            $result = app(DurableSwarmManager::class)->signal($runId, 'second', ['approved' => true], 'key-1');

            return ['outcome' => 'returned', 'duplicate' => $result->duplicate, 'accepted' => $result->accepted];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        } finally {
            LockBarrier::signal($dir, 'repeat-done');
        }
    };
}

beforeEach(function (): void {
    if (in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true)) {
        $this->markTestSkipped(
            'The signal acceptance abort test requires a shared database engine reachable from '
            .'child processes (mysql/pgsql). Current driver: '.DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');
    config()->set('queue.default', 'null');

    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
});

afterEach(function (): void {
    foreach (signalAbortDirs(clear: true) as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

/**
 * @return array{0: string, 1: string}
 */
function signalAbortWaitingRuns(): array
{
    $runId = FakeSequentialSwarm::make()->dispatchDurable('signal-abort-task')->runId;
    app(DurableSwarmManager::class)->wait($runId, 'approval');
    $otherRunId = FakeSequentialSwarm::make()->dispatchDurable('unrelated-task')->runId;

    return [$runId, $otherRunId];
}

function signalAbortAssertAcceptedOnce(string $runId, array $signaller): void
{
    expect($signaller['second'])->toBe(['accepted' => true, 'duplicate' => false], json_encode($signaller))
        ->and($signaller['third'])->toBe(['accepted' => false, 'duplicate' => true])
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(1)
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->value('status'))->toBe('consumed')
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->value('status'))->toBe('signalled')
        ->and(DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status'))->toBe('pending');
}

test('a signal whose acceptance times out on a lock leaves nothing behind and is accepted once when sent again', function (): void {
    [$runId, $otherRunId] = signalAbortWaitingRuns();
    $dir = signalAbortDir();

    [, $signaller] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        signalAbortHoldingContext($runId, $dir),
        signalAbortSignaller($runId, $otherRunId, $dir, insideApplicationTransaction: false),
    ]);

    // A driver error (MySQL 1205 / HY000, Postgres 55P03), not a test exception.
    expect($signaller['first'])->toStartWith('sqlstate:', json_encode($signaller));
    signalAbortAssertAcceptedOnce($runId, $signaller);
});

test('inside an application transaction that catches the timed-out acceptance and commits, the signal is still accepted once when sent again', function (): void {
    [$runId, $otherRunId] = signalAbortWaitingRuns();
    $dir = signalAbortDir();

    [, $signaller] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        signalAbortHoldingContext($runId, $dir),
        signalAbortSignaller($runId, $otherRunId, $dir, insideApplicationTransaction: true),
    ]);

    expect($signaller['first'])->toStartWith('sqlstate:', json_encode($signaller))
        ->and($signaller['usable'])->toBeTrue()
        // The application's own write committed with its transaction.
        ->and(app(DurableSwarmManager::class)->inspect($otherRunId)->details)->toBe(['written' => 'by-the-application']);
    signalAbortAssertAcceptedOnce($runId, $signaller);
});

test('two waits of one run receiving the same idempotency key at once accept it once and leave the other wait open', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('shared-key-task')->runId;
    app(DurableSwarmManager::class)->wait($runId, 'a');
    app(DurableSwarmManager::class)->wait($runId, 'b');
    $dir = signalAbortDir();

    [$first, $second] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        signalAbortHoldingSender($runId, $dir),
        signalAbortSecondSender($runId, $dir),
    ]);

    // The second sender waits on the first's locks before it reads the key, then reads it
    // as already recorded: a duplicate that releases nothing.
    expect($first)->toBe(['accepted' => true, 'duplicate' => false, 'contended' => true], json_encode([$first, $second]))
        ->and($second)->toBe(['accepted' => false, 'duplicate' => true])
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(1)
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', 'a')->value('status'))->toBe('signalled')
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', 'b')->value('status'))->toBe('waiting')
        ->and(DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status'))->toBe('waiting');
});

test('two signals closing two waits of one run at once are both accepted without a deadlock', function (): void {
    // Each acceptance inserts its signal row only once it holds the run row: inserted
    // first, its foreign-key check would take a shared lock on the run row, and two
    // acceptances each holding one and asking for the write lock deadlock on MySQL.
    $runId = FakeSequentialSwarm::make()->dispatchDurable('two-signals-task')->runId;
    app(DurableSwarmManager::class)->wait($runId, 'a');
    app(DurableSwarmManager::class)->wait($runId, 'b');
    $dir = signalAbortDir();

    [$first, $second] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        signalAbortPairedSender($runId, 'a', 'first', 'second', $dir),
        signalAbortPairedSender($runId, 'b', 'second', 'first', $dir),
    ]);

    // The handshake must have seen both acceptances in flight, or one blocked on the
    // other: a timed-out handshake (null) means the race was not exercised.
    expect($first['outcome'])->toBe('accepted', json_encode([$first, $second]))
        ->and($second['outcome'])->toBe('accepted', json_encode([$first, $second]))
        ->and([$first['paired'], $second['paired']])->each->toBeIn(['both-in-flight', 'other-blocked'])
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->where('status', 'consumed')->count())->toBe(2)
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('status', 'signalled')->count())->toBe(2)
        ->and(DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status'))->toBe('pending');
});

test('a repeated idempotency key is answered as a duplicate without waiting on the run row', function (): void {
    // A webhook's retries of a signal already accepted must not each lock the run: the
    // repeat is answered from the recorded key before any acceptance is attempted.
    $runId = FakeSequentialSwarm::make()->dispatchDurable('repeat-key-task')->runId;
    app(DurableSwarmManager::class)->wait($runId, 'approval');
    app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true], 'key-1');
    app(DurableSwarmManager::class)->wait($runId, 'second');
    $dir = signalAbortDir();

    [, $repeat] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        signalAbortHoldingRun($runId, $dir),
        signalAbortRepeatSender($runId, $dir),
    ]);

    expect($repeat)->toBe(['outcome' => 'returned', 'duplicate' => true, 'accepted' => false], json_encode($repeat))
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->count())->toBe(1)
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', 'second')->value('status'))->toBe('waiting');
});
