<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ChildDispatchingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableWaitAttributeSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\HookedSyncDurableRunStore;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\LockBarrier;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\ResumeOpenWaitWorker;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

/**
 * Resuming a durable run paused in a signal or child-swarm wait (#592), on the real
 * engines. resume() decides `waiting` vs `pending` from a shared locking read of the
 * run's open waits, taken before the run row is written — the order every wait
 * release takes (wait UPDATE, then syncRunWaitSummary on the run).
 *
 * The scenario tests prove the query shapes run on MySQL and Postgres. The race tests
 * run two workers through the process concurrency driver and use LockBarrier to
 * observe, from the engine's lock-wait view, that a worker is actually blocked on the
 * other's lock before letting it continue — no timed holds.
 *
 * Every worker closure is built by a free function, so its scope class is null and it
 * serializes cleanly to the child process, which runs it against bare testbench (no
 * Pest runtime, not this file): ResumeOpenWaitWorker::boot() repeats the package
 * configuration, and only autoloaded classes are referenced.
 */
// Tagged `skip-locked-real-db` so the CI lane (`test:process-concurrency:ci`)
// excludes it under `--fail-on-skipped`: it requires a shared MySQL/Postgres
// connection and skips on the default in-memory SQLite, which is per-process and
// cannot be reached by the child processes the concurrency driver spawns.
pest()->group('process-concurrency', 'skip-locked-real-db');

function durableResumeOpenWaitDriverSupported(): bool
{
    return ! in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true);
}

function durableResumeOpenWaitBarrierDir(): string
{
    $dir = sys_get_temp_dir().'/laravel-swarm-resume-open-wait-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    durableResumeOpenWaitBarrierDirs($dir);

    return $dir;
}

/**
 * The barrier directories this test process created, so afterEach can remove them.
 *
 * @return array<int, string>
 */
function durableResumeOpenWaitBarrierDirs(?string $add = null, bool $clear = false): array
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
 * finding MYSQL-LEASE), which leaves the run `running`; this test is not about it.
 *
 * @param  array<int, string>  $outputs
 */
function durableResumeOpenWaitSlowOutputs(array $outputs): Closure
{
    return function () use (&$outputs): string {
        usleep(1_100_000);

        return (string) array_shift($outputs);
    };
}

/**
 * A pending sequential run with the named waits opened on it (oldest first). No step
 * runs, so the waits are the only reason the run is waiting.
 *
 * @param  array<string, array{0: string, 1: int|null}>  $waits
 */
function durableResumeOpenWaitRun(array $waits): string
{
    $runId = FakeSequentialSwarm::make()->dispatchDurable('resume-open-wait')->runId;
    $manager = app(DurableSwarmManager::class);

    foreach ($waits as $name => [$reason, $timeout]) {
        $manager->wait($runId, $name, $reason, $timeout);
    }

    return $runId;
}

/**
 * @return array<string, string>
 */
function durableResumeOpenWaitStatuses(string $runId): array
{
    return DB::table('swarm_durable_waits')
        ->where('run_id', $runId)
        ->orderBy('id')
        ->pluck('status', 'name')
        ->all();
}

function durableResumeOpenWaitRunStatus(string $runId): ?string
{
    return DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status');
}

/**
 * Releases the wait (the child-completion path) inside a transaction and keeps the
 * transaction open until the resumer is observed blocked on this worker's lock.
 */
function durableResumeOpenWaitHoldingReleaser(string $runId, string $wait, string $dir): Closure
{
    return static function () use ($runId, $wait, $dir): array {
        ResumeOpenWaitWorker::boot();
        $me = LockBarrier::connectionId();

        DB::beginTransaction();
        app(DurableRunStore::class)->releaseWaitWithOutcome($runId, $wait, 'child_completed', ['status' => 'completed', 'timed_out' => false]);
        LockBarrier::signal($dir, 'releaser-holds-wait');

        $resumer = (int) LockBarrier::await($dir, 'resumer-id');

        if (! LockBarrier::awaitBlockedBy($resumer, $me)) {
            $locks = LockBarrier::lockWaitSnapshot();
            DB::rollBack();

            return ['outcome' => 'no-contention', 'locks' => $locks];
        }

        DB::commit();

        return ['outcome' => 'committed'];
    };
}

/**
 * Releases the wait through a store that stops after the wait-row UPDATE and before
 * syncRunWaitSummary() asks for the run row, until the resumer is observed blocked.
 */
function durableResumeOpenWaitHookedReleaser(string $runId, string $wait, string $dir): Closure
{
    return static function () use ($runId, $wait, $dir): array {
        ResumeOpenWaitWorker::boot();
        $me = LockBarrier::connectionId();
        $store = app(HookedSyncDurableRunStore::class);
        $contended = false;
        $locks = null;
        $store->beforeSync = static function () use ($dir, $me, &$contended, &$locks): void {
            LockBarrier::signal($dir, 'releaser-holds-wait');
            $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'resumer-id'), $me);

            if (! $contended) {
                $locks = LockBarrier::lockWaitSnapshot();
            }
        };

        try {
            DB::transaction(fn () => $store->releaseWaitWithOutcome($runId, $wait, 'child_completed', ['status' => 'completed', 'timed_out' => false]));
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e), 'contended' => $contended];
        }

        return $contended
            ? ['outcome' => 'committed', 'contended' => true]
            : ['outcome' => 'committed', 'contended' => false, 'locks' => $locks];
    };
}

/**
 * The full manager resume (its own transaction), started once the releaser holds the
 * wait row.
 */
function durableResumeOpenWaitResumer(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        ResumeOpenWaitWorker::boot();
        LockBarrier::signal($dir, 'resumer-id', (string) LockBarrier::connectionId());
        LockBarrier::await($dir, 'releaser-holds-wait');

        try {
            return ['outcome' => app(DurableSwarmManager::class)->resume($runId)->status->value];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

/**
 * Takes a snapshot (an ordinary read opens the REPEATABLE READ view on MySQL), lets
 * the opener commit a wait, then resumes inside the same application transaction.
 */
function durableResumeOpenWaitSnapshotResumer(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        ResumeOpenWaitWorker::boot();

        DB::beginTransaction();
        $seen = DB::table('swarm_durable_waits')->where('run_id', $runId)->count();
        DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status');
        LockBarrier::signal($dir, 'snapshot-taken');
        LockBarrier::await($dir, 'wait-created');
        $status = app(DurableSwarmManager::class)->resume($runId)->status->value;
        DB::commit();

        return ['waits_in_snapshot' => $seen, 'outcome' => $status];
    };
}

function durableResumeOpenWaitOpener(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        ResumeOpenWaitWorker::boot();
        LockBarrier::await($dir, 'snapshot-taken');
        app(DurableSwarmManager::class)->wait($runId, 'late', 'Opened while paused');
        LockBarrier::signal($dir, 'wait-created');

        return ['outcome' => 'created'];
    };
}

/**
 * Pauses the run inside an application transaction (holding the run row), waits for
 * the releaser to block on that row, then resumes in the same transaction.
 */
function durableResumeOpenWaitPauseResumeOperator(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        ResumeOpenWaitWorker::boot();
        $me = LockBarrier::connectionId();
        $manager = app(DurableSwarmManager::class);
        DB::beginTransaction();

        try {
            $manager->pause($runId);
            LockBarrier::signal($dir, 'operator-paused');

            if (! LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'releaser-id'), $me)) {
                $locks = LockBarrier::lockWaitSnapshot();
                DB::rollBack();

                return ['outcome' => 'no-contention', 'locks' => $locks];
            }

            $status = $manager->resume($runId)->status->value;
            DB::commit();

            return ['outcome' => 'committed', 'status' => $status];
        } catch (Throwable $e) {
            try {
                DB::rollBack();
            } catch (Throwable) {
                // Already rolled back by the engine's deadlock abort.
            }

            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

function durableResumeOpenWaitBlockingReleaser(string $runId, string $wait, string $dir): Closure
{
    return static function () use ($runId, $wait, $dir): array {
        ResumeOpenWaitWorker::boot();
        LockBarrier::signal($dir, 'releaser-id', (string) LockBarrier::connectionId());
        LockBarrier::await($dir, 'operator-paused');

        try {
            DB::transaction(fn () => app(DurableRunStore::class)->releaseWaitWithOutcome($runId, $wait, 'child_completed', ['status' => 'completed', 'timed_out' => false]));
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => 'committed'];
    };
}

beforeEach(function (): void {
    if (! durableResumeOpenWaitDriverSupported()) {
        $this->markTestSkipped(
            'Durable resume/open-wait test requires a shared database engine '
            .'reachable from child processes (mysql/pgsql). Current driver: '
            .DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.durable.queue.connection', 'durable-test');
});

afterEach(function (): void {
    foreach (durableResumeOpenWaitBarrierDirs(clear: true) as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

test('a run paused in a signal wait resumes into the wait on the real engine', function (): void {
    FakeResearcher::fake(durableResumeOpenWaitSlowOutputs(['research-out']));
    FakeWriter::fake(durableResumeOpenWaitSlowOutputs(['writer-out']));
    $response = DurableWaitAttributeSwarm::make()->dispatchDurable('wait-task');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $manager->pause($runId);

    expect($manager->resume($runId)->isWaiting())->toBeTrue()
        ->and($manager->find($runId)['status'])->toBe('waiting');

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('waiting')
        ->and($manager->find($runId)['next_step_index'])->toBe(1)
        ->and($response->signal('approval_received', ['approved' => true], 'real-db')->accepted)->toBeTrue();

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('completed');
});

test('a run paused in a child swarm wait resumes into the wait on the real engine', function (): void {
    FakeResearcher::fake(durableResumeOpenWaitSlowOutputs(['parent-step', 'child-research']));
    FakeWriter::fake(durableResumeOpenWaitSlowOutputs(['parent-final', 'child-writer']));
    FakeEditor::fake(durableResumeOpenWaitSlowOutputs(['child-output']));
    $runId = ChildDispatchingSwarm::make()->dispatchDurable('parent-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $childRunId = (string) $manager->inspect($runId)->children[0]['child_run_id'];
    $manager->pause($runId);

    expect($manager->resume($runId)->isWaiting())->toBeTrue()
        ->and($manager->find($runId)['status'])->toBe('waiting');

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('waiting');

    (new AdvanceDurableSwarm($childRunId, 0))->handle($manager);
    (new AdvanceDurableSwarm($childRunId, 1))->handle($manager);
    (new AdvanceDurableSwarm($childRunId, 2))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('pending');

    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('completed');
});

test('resume waits for an in-flight release of the same wait instead of reading it as open', function (): void {
    $runId = durableResumeOpenWaitRun(['approval' => ['Approval', null]]);
    app(DurableSwarmManager::class)->pause($runId);
    $dir = durableResumeOpenWaitBarrierDir();
    $stepRows = fn (): int => DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'step')->count();
    $stepRowsBefore = $stepRows();

    [$releaser, $resumer] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        durableResumeOpenWaitHoldingReleaser($runId, 'approval', $dir),
        durableResumeOpenWaitResumer($runId, $dir),
    ]);

    // resume() saw the wait closed, released the run to pending and enqueued its step.
    expect($releaser)->toBe(['outcome' => 'committed'])
        ->and($resumer)->toBe(['outcome' => 'resumed'])
        ->and(durableResumeOpenWaitRunStatus($runId))->toBe('pending')
        ->and(durableResumeOpenWaitStatuses($runId))->toBe(['approval' => 'child_completed'])
        ->and($stepRows())->toBe($stepRowsBefore + 1);
});

test('resume and a wait release lock wait rows before the run row, so they never deadlock', function (): void {
    $runId = durableResumeOpenWaitRun([
        'manual' => ['Manual', null],
        'gate' => ['Gate', null],
    ]);
    app(DurableSwarmManager::class)->pause($runId);
    $dir = durableResumeOpenWaitBarrierDir();

    [$releaser, $resumer] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        durableResumeOpenWaitHookedReleaser($runId, 'gate', $dir),
        durableResumeOpenWaitResumer($runId, $dir),
    ]);

    expect($releaser)->toBe(['outcome' => 'committed', 'contended' => true])
        ->and($resumer)->toBe(['outcome' => 'waiting'])
        ->and(durableResumeOpenWaitRunStatus($runId))->toBe('waiting')
        ->and(durableResumeOpenWaitStatuses($runId))->toBe(['manual' => 'waiting', 'gate' => 'child_completed']);
});

test('resume reads the open waits current even inside an older application snapshot', function (): void {
    // A run paused before any wait exists; a wait is opened on it while paused and
    // committed BEFORE the resume. A resume reading through the older snapshot would see
    // no wait and release the run to pending. Discriminating on MySQL (REPEATABLE READ);
    // Postgres READ COMMITTED takes a fresh snapshot per statement and passes either way.
    $runId = durableResumeOpenWaitRun([]);
    app(DurableSwarmManager::class)->pause($runId);
    $dir = durableResumeOpenWaitBarrierDir();

    [$resumer, $opener] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        durableResumeOpenWaitSnapshotResumer($runId, $dir),
        durableResumeOpenWaitOpener($runId, $dir),
    ]);

    expect($opener)->toBe(['outcome' => 'created'])
        ->and($resumer)->toBe(['waits_in_snapshot' => 0, 'outcome' => 'waiting'])
        ->and(durableResumeOpenWaitRunStatus($runId))->toBe('waiting');
});

test('an application transaction that pauses and resumes a run during a wait release aborts one side cleanly', function (): void {
    // Accepted outcome (operator decision, #592): the operator's transaction already
    // holds the run row from its own pause when it resumes, while the releaser holds the
    // wait row and waits for the run row. The engine aborts one of them; nothing is
    // half-written, and retrying the aborted side succeeds.
    $runId = durableResumeOpenWaitRun(['approval' => ['Approval', null]]);
    $dir = durableResumeOpenWaitBarrierDir();

    [$operator, $releaser] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        durableResumeOpenWaitPauseResumeOperator($runId, $dir),
        durableResumeOpenWaitBlockingReleaser($runId, 'approval', $dir),
    ]);

    // Fail with each worker's whole result (its lock snapshot included) if the barrier
    // never saw the contention this test is about.
    expect($operator['outcome'])->not->toBe('no-contention', json_encode($operator))
        ->and($releaser['outcome'])->toBeIn(['committed', 'aborted'], json_encode($releaser));

    $outcomes = [$operator['outcome'], $releaser['outcome']];
    sort($outcomes);
    $aborted = $releaser['outcome'] === 'aborted' ? $releaser : $operator;

    expect($outcomes)->toBe(['aborted', 'committed'])
        ->and($aborted['failure'])->toBeIn(['sqlstate:40001', 'sqlstate:40P01']);

    if ($releaser['outcome'] === 'aborted') {
        expect(durableResumeOpenWaitRunStatus($runId))->toBe('waiting')
            ->and(durableResumeOpenWaitStatuses($runId))->toBe(['approval' => 'waiting']);

        DB::transaction(fn () => app(DurableRunStore::class)->releaseWaitWithOutcome($runId, 'approval', 'child_completed', ['status' => 'completed', 'timed_out' => false]));
    } else {
        expect(durableResumeOpenWaitRunStatus($runId))->toBe('pending')
            ->and(durableResumeOpenWaitStatuses($runId))->toBe(['approval' => 'child_completed']);

        $manager = app(DurableSwarmManager::class);
        DB::transaction(function () use ($manager, $runId): void {
            $manager->pause($runId);
            $manager->resume($runId);
        });
    }

    expect(durableResumeOpenWaitRunStatus($runId))->toBe('pending')
        ->and(durableResumeOpenWaitStatuses($runId))->toBe(['approval' => 'child_completed']);
});
