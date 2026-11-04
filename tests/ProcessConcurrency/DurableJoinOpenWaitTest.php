<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\JoinOpenWait\JoinOpenWaitWorker;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\LockBarrier;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;

/**
 * A durable parent at a branch join that also has an open wait is not released to
 * its join until every wait is closed (#600), on the real engines.
 *
 * The scenario tests run both acceptance workflows end to end on MySQL and Postgres.
 * The race tests run two workers through the process concurrency driver and use
 * LockBarrier to observe, from the engine's lock-wait view, that a worker is actually
 * blocked on the other's lock before letting it continue — no timed holds. The join
 * release locks the run's open wait rows before the run row; a wait closer holds its
 * wait row and then the run row, and only tries the join once it has committed.
 *
 * Every worker closure is built by a free function, so its scope class is null and it
 * serializes cleanly to the child process, which runs it against bare testbench (no
 * Pest runtime, not this file): JoinOpenWaitWorker::boot() repeats the package
 * configuration, and only autoloaded classes are referenced.
 */
// Tagged `skip-locked-real-db` so the CI lane (`test:process-concurrency:ci`)
// excludes it under `--fail-on-skipped`: it requires a shared MySQL/Postgres
// connection and skips on the default in-memory SQLite.
pest()->group('process-concurrency', 'skip-locked-real-db');

function joinOpenWaitDbBarrierDir(): string
{
    $dir = sys_get_temp_dir().'/laravel-swarm-join-open-wait-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    joinOpenWaitDbBarrierDirs($dir);

    return $dir;
}

/**
 * @return array<int, string>
 */
function joinOpenWaitDbBarrierDirs(?string $add = null, bool $clear = false): array
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
 * Fake agent outputs that each take longer than a second. The delay was added while
 * MySQL read a step that started and finished within one second as a lost history
 * lease (release finding MYSQL-LEASE, since fixed); it is kept so these tests' timing
 * is unchanged.
 *
 * @param  array<int, string>  $outputs
 */
function joinOpenWaitDbSlow(array $outputs): Closure
{
    return function () use (&$outputs): string {
        usleep(1_100_000);

        return (string) array_shift($outputs);
    };
}

function joinOpenWaitDbFakeAgents(): void
{
    FakeResearcher::fake(joinOpenWaitDbSlow(['research-out', 'research-out']));
    FakeWriter::fake(joinOpenWaitDbSlow(['writer-out', 'writer-out']));
    FakeEditor::fake(joinOpenWaitDbSlow(['editor-out', 'editor-out']));
}

/**
 * The coordinator's route plan, answered after more than a second (the delay dates
 * from MYSQL-LEASE, as above, and is kept for the same reason).
 *
 * @param  array<string, mixed>  $plan
 */
function joinOpenWaitDbFakeCoordinator(array $plan): void
{
    FakeHierarchicalCoordinator::fake(function () use ($plan): array {
        usleep(1_100_000);

        return $plan;
    });
}

function joinOpenWaitDbPlan(): array
{
    return [
        'start_at' => 'parallel_node',
        'nodes' => [
            'parallel_node' => ['type' => 'parallel', 'branches' => ['writer_node', 'editor_node'], 'next' => 'finish_node'],
            'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
            'editor_node' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor-branch'],
            'finish_node' => ['type' => 'finish', 'output_from' => 'editor_node'],
        ],
    ];
}

function joinOpenWaitDbStepRows(string $runId): int
{
    return DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'step')->count();
}

function joinOpenWaitDbResumeRows(string $runId): int
{
    return DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'queued_resume')->count();
}

function joinOpenWaitDbRunStatus(string $runId): ?string
{
    return DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status');
}

function joinOpenWaitDbMarkBranchesCompleted(string $runId, ?string $exceptBranchId = null): void
{
    DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->when($exceptBranchId !== null, fn ($query) => $query->where('branch_id', '!=', $exceptBranchId))
        ->update([
            'status' => 'completed',
            'output' => 'branch-out',
            'usage' => json_encode([]),
            'duration_ms' => 1,
            'finished_at' => now('UTC'),
            'execution_token' => null,
            'leased_until' => null,
            'updated_at' => now('UTC')->subMinutes(10),
        ]);
}

/**
 * A step-durable top-level parallel run at its join with an open wait (and, when
 * given, more). Returns [runId, last branch id] — every other branch is completed.
 *
 * @param  array<int, string>  $waits
 * @return array{0: string, 1: string}
 */
function joinOpenWaitDbParallelAtJoin(array $waits = ['approval'], bool $leaveLastBranch = true): array
{
    $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    foreach ($waits as $wait) {
        $manager->wait($runId, $wait);
    }

    $branches = app(DurableRunStore::class)->branchesFor($runId, 'parallel');
    $last = (string) end($branches)['branch_id'];
    joinOpenWaitDbMarkBranchesCompleted($runId, $leaveLastBranch ? $last : null);

    return [$runId, $last];
}

/**
 * Advances one branch to completion (its terminal write commits, then its join
 * release runs) once the other worker holds its lock.
 */
function joinOpenWaitDbBranchWorker(string $runId, string $branchId, string $dir, string $after): Closure
{
    return static function () use ($runId, $branchId, $dir, $after): array {
        JoinOpenWaitWorker::boot();
        FakeResearcher::fake(['research-out']);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
        LockBarrier::signal($dir, 'branch-id', (string) LockBarrier::connectionId());
        LockBarrier::await($dir, $after);

        try {
            (new AdvanceDurableBranch($runId, $branchId))->handle(app(DurableSwarmManager::class));
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        LockBarrier::signal($dir, 'branch-done');

        return ['outcome' => 'completed'];
    };
}

/**
 * Signals the wait through a store that stops once the wait row is UPDATEd and before
 * the run row is asked for, until the branch worker is observed blocked on it.
 */
function joinOpenWaitDbHoldingSignaller(string $runId, string $wait, string $dir): Closure
{
    return static function () use ($runId, $wait, $dir): array {
        $store = JoinOpenWaitWorker::bootHooked();
        $me = LockBarrier::connectionId();
        $contended = false;
        $locks = null;
        $store->beforeSync = static function () use ($dir, $me, &$contended, &$locks): void {
            LockBarrier::signal($dir, 'signaller-holds-wait');
            $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'branch-id'), $me);
            $locks = $contended ? null : LockBarrier::lockWaitSnapshot();
        };

        try {
            $accepted = app(DurableSwarmManager::class)->signal($runId, $wait)->accepted;
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => $accepted ? 'accepted' : 'refused', 'contended' => $contended, 'locks' => $locks];
    };
}

/**
 * Advances the last branch through a store that, once its join release holds its
 * shared locks on the open waits, waits until the signaller is observed blocked on
 * them.
 */
function joinOpenWaitDbHoldingBranch(string $runId, string $branchId, string $dir): Closure
{
    return static function () use ($runId, $branchId, $dir): array {
        $store = JoinOpenWaitWorker::bootHooked();
        FakeResearcher::fake(['research-out']);
        FakeWriter::fake(['writer-out']);
        FakeEditor::fake(['editor-out']);
        $me = LockBarrier::connectionId();
        $contended = false;
        $released = null;
        $locks = null;
        $store->afterJoinCheck = static function (string $runId, bool $result) use ($dir, $me, &$contended, &$released, &$locks): void {
            $released = $result;
            LockBarrier::signal($dir, 'branch-holds-waits');
            $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'signaller-id'), $me);
            $locks = $contended ? null : LockBarrier::lockWaitSnapshot();
        };

        try {
            (new AdvanceDurableBranch($runId, $branchId))->handle(app(DurableSwarmManager::class));
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => 'completed', 'released' => $released, 'contended' => $contended, 'locks' => $locks];
    };
}

function joinOpenWaitDbSignaller(string $runId, string $wait, string $dir, string $after): Closure
{
    return static function () use ($runId, $wait, $dir, $after): array {
        JoinOpenWaitWorker::boot();
        LockBarrier::signal($dir, 'signaller-id', (string) LockBarrier::connectionId());
        LockBarrier::await($dir, $after);

        try {
            return ['outcome' => app(DurableSwarmManager::class)->signal($runId, $wait)->accepted ? 'accepted' : 'refused'];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

/**
 * Signals a wait through a store that stops once the release has written the run
 * row too (it holds the wait row and the run row) until the second closer is
 * observed blocked on it.
 */
function joinOpenWaitDbRunHoldingSignaller(string $runId, string $wait, string $dir): Closure
{
    return static function () use ($runId, $wait, $dir): array {
        $store = JoinOpenWaitWorker::bootHooked();
        $me = LockBarrier::connectionId();
        $contended = false;
        $locks = null;
        $store->afterSync = static function () use ($dir, $me, &$contended, &$locks): void {
            LockBarrier::signal($dir, 'first-holds-run');
            $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'signaller-id'), $me);
            $locks = $contended ? null : LockBarrier::lockWaitSnapshot();
        };

        try {
            $accepted = app(DurableSwarmManager::class)->signal($runId, $wait)->accepted;
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => $accepted ? 'accepted' : 'refused', 'contended' => $contended, 'locks' => $locks];
    };
}

/**
 * swarm:recover's timed-out-wait pass through a store that stops once the wait is
 * closed and the run row written, before the outcome and history are published,
 * until the branch worker is observed blocked on it.
 */
function joinOpenWaitDbHoldingTimeoutSweep(string $dir): Closure
{
    return static function () use ($dir): array {
        $store = JoinOpenWaitWorker::bootHooked();
        $me = LockBarrier::connectionId();
        $contended = false;
        $locks = null;
        $store->afterSync = static function () use ($dir, $me, &$contended, &$locks): void {
            LockBarrier::signal($dir, 'sweep-holds-wait');
            $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'branch-id'), $me);
            $locks = $contended ? null : LockBarrier::lockWaitSnapshot();
        };

        try {
            app(DurableSwarmManager::class)->recover();
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => 'swept', 'contended' => $contended, 'locks' => $locks];
    };
}

/**
 * Opens an application transaction, takes a snapshot while the last branch is still
 * running, lets that branch finish (and be refused its join: the wait is open), then
 * signals inside the same transaction. Reports what it saw before its commit.
 */
function joinOpenWaitDbSnapshotSignaller(string $runId, string $wait, string $dir): Closure
{
    return static function () use ($runId, $wait, $dir): array {
        JoinOpenWaitWorker::boot();
        $stepRows = fn (): int => DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'step')->count();

        DB::beginTransaction();
        $activeInSnapshot = DB::table('swarm_durable_branches')->where('run_id', $runId)->where('status', '!=', 'completed')->count();
        LockBarrier::signal($dir, 'snapshot-taken');
        LockBarrier::await($dir, 'branch-done');
        $accepted = app(DurableSwarmManager::class)->signal($runId, $wait)->accepted;
        $beforeCommit = ['status' => DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status'), 'step_rows' => $stepRows()];
        DB::commit();

        return ['active_in_snapshot' => $activeInSnapshot, 'accepted' => $accepted, 'before_commit' => $beforeCommit];
    };
}

/**
 * Plays a fan-out (waitForBranches) on a run: inserts its branch rows and moves the
 * run to `waiting` at the parallel node, then holds that uncommitted transaction —
 * so it holds the run row — until the closer is observed blocked on it.
 *
 * @param  array<int, array<string, mixed>>  $branchRows
 */
function joinOpenWaitDbHoldingFanOut(string $runId, array $branchRows, string $dir): Closure
{
    return static function () use ($runId, $branchRows, $dir): array {
        JoinOpenWaitWorker::boot();
        $me = LockBarrier::connectionId();

        DB::beginTransaction();
        DB::table('swarm_durable_branches')->insert($branchRows);
        DB::table('swarm_durable_runs')->where('run_id', $runId)->update([
            'status' => 'waiting',
            'current_node_id' => 'parallel',
            'next_step_index' => 3,
        ]);
        LockBarrier::signal($dir, 'fanout-holds-run');

        if (! LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'signaller-id'), $me)) {
            $locks = LockBarrier::lockWaitSnapshot();
            DB::rollBack();

            return ['outcome' => 'no-contention', 'locks' => $locks];
        }

        DB::commit();

        return ['outcome' => 'committed'];
    };
}

/**
 * Plays a branch retry (DurableRetryHandler): holds the run's branch rows
 * write-locked, and only once the closer has finished, or is observed blocked on
 * those rows, writes its outbox row — whose foreign key needs the run row.
 */
function joinOpenWaitDbHoldingRetry(string $runId, string $branchId, string $dir): Closure
{
    return static function () use ($runId, $branchId, $dir): array {
        JoinOpenWaitWorker::boot();
        $me = LockBarrier::connectionId();

        try {
            DB::beginTransaction();
            DB::table('swarm_durable_branches')->where('run_id', $runId)->update(['updated_at' => now('UTC')]);
            LockBarrier::signal($dir, 'retry-holds-branches');
            $closer = (int) LockBarrier::await($dir, 'signaller-id');
            $deadline = microtime(true) + 15.0;
            $closerBlocked = false;

            while (! is_file($dir.'/signaller-done') && microtime(true) < $deadline) {
                if (LockBarrier::isBlockedBy($closer, $me)) {
                    $closerBlocked = true;

                    break;
                }

                usleep(10_000);
            }

            app(DurableOutbox::class)->enqueueBranch($runId, $branchId, 'durable-test', 'swarm-durable');
            DB::commit();

            return ['outcome' => 'committed', 'closer_blocked_on_branches' => $closerBlocked];
        } catch (Throwable $e) {
            try {
                DB::rollBack();
            } catch (Throwable) {
            }

            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

function joinOpenWaitDbSignallerAfter(string $runId, string $wait, string $dir, string $after): Closure
{
    return static function () use ($runId, $wait, $dir, $after): array {
        JoinOpenWaitWorker::boot();
        LockBarrier::signal($dir, 'signaller-id', (string) LockBarrier::connectionId());
        LockBarrier::await($dir, $after);

        try {
            $accepted = app(DurableSwarmManager::class)->signal($runId, $wait)->accepted;
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        LockBarrier::signal($dir, 'signaller-done');

        return ['outcome' => $accepted ? 'accepted' : 'refused'];
    };
}

beforeEach(function (): void {
    if (in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true)) {
        $this->markTestSkipped(
            'Durable join/open-wait test requires a shared database engine reachable from '
            .'child processes (mysql/pgsql). Current driver: '.DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');
    config()->set('queue.default', 'null');
    config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');

    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, QueuedHierarchicalCoordinator::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    joinOpenWaitDbFakeAgents();
});

afterEach(function (): void {
    foreach (joinOpenWaitDbBarrierDirs(clear: true) as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

test('step-durable parallel: branch completion, resume and recover hold the join while a wait is open; the signal releases it', function (): void {
    [$runId, $last] = joinOpenWaitDbParallelAtJoin();
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableBranch($runId, $last))->handle($manager);
    $stepRows = joinOpenWaitDbStepRows($runId);

    expect(joinOpenWaitDbRunStatus($runId))->toBe('waiting');

    $manager->pause($runId);
    expect($manager->resume($runId)->isWaiting())->toBeTrue()
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('waiting');

    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['updated_at' => now('UTC')->subMinutes(10)]);
    expect(app(DurableRunStore::class)->recoverableWaitingJoins(runId: $runId))->toBe([]);
    Artisan::call('swarm:recover');

    expect(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($stepRows);

    expect($manager->signal($runId, 'approval')->accepted)->toBeTrue()
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($stepRows + 1);

    (new AdvanceDurableSwarm($runId, 3))->handle($manager);

    expect(joinOpenWaitDbRunStatus($runId))->toBe('completed');
});

test('queued-hierarchical: branch completion, resume and recover hold the join while a wait is open; the signal runs the continuation to completion', function (): void {
    joinOpenWaitDbFakeCoordinator(joinOpenWaitDbPlan());
    $runId = 'join-open-wait-db-qh-'.bin2hex(random_bytes(4));
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, RunContext::from('queued-hierarchical-task', $runId)->toQueuePayload()))->handle(app(SwarmRunner::class));
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'approval');

    foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel_node') as $branch) {
        (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle($manager);
    }

    $manager->pause($runId);
    $manager->resume($runId);
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['updated_at' => now('UTC')->subMinutes(10)]);
    Artisan::call('swarm:recover');

    expect(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
        ->and(joinOpenWaitDbResumeRows($runId))->toBe(0);

    expect($manager->signal($runId, 'approval')->accepted)->toBeTrue()
        ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and(joinOpenWaitDbResumeRows($runId))->toBe(1);

    (new ResumeQueuedHierarchicalSwarm($runId))->handle(app(QueuedHierarchicalCoordinator::class));

    expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
});

test('queued-hierarchical fail_run: a signal between the branch failure and the parent failure does not run the continuation', function (): void {
    config()->set('swarm.durable.parallel.failure_policy', 'fail_run');
    joinOpenWaitDbFakeCoordinator([
        'start_at' => 'parallel_node',
        'nodes' => [
            'parallel_node' => ['type' => 'parallel', 'branches' => ['writer_node', 'failing_node'], 'next' => 'finish_node'],
            'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
            'failing_node' => ['type' => 'worker', 'agent' => FailingPromptAgent::class, 'prompt' => 'failing-branch'],
            'finish_node' => ['type' => 'finish', 'output_from' => 'writer_node'],
        ],
    ]);
    $runId = 'join-open-wait-db-fail-'.bin2hex(random_bytes(4));
    $logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $hookRan = false;
    $logger->shouldReceive('error')->andReturnUsing(function (string|Stringable $message) use ($runId, &$hookRan): void {
        if (str_contains((string) $message, 'branch failed')) {
            $hookRan = true;
            app(DurableSwarmManager::class)->signal($runId, 'approval');
        }
    });
    app()->instance(LoggerInterface::class, $logger);
    app()->forgetInstance(DurableSwarmManager::class);

    (new InvokeSwarm(FakeHierarchicalParallelFailBranchSwarm::class, RunContext::from('queued-hierarchical-task', $runId)->toQueuePayload()))->handle(app(SwarmRunner::class));
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'approval');
    $branches = collect(app(DurableRunStore::class)->branchesFor($runId, 'parallel_node'));

    (new AdvanceDurableBranch($runId, (string) $branches->firstWhere('agent_class', FakeWriter::class)['branch_id']))->handle($manager);

    (new AdvanceDurableBranch($runId, (string) $branches->firstWhere('agent_class', FailingPromptAgent::class)['branch_id']))->handle($manager);

    expect($hookRan)->toBeTrue()
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('failed')
        ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('failed')
        ->and(joinOpenWaitDbResumeRows($runId))->toBe(0);
});

test('a signal closing the wait while the last branch asks for the join: the join waits for the closure, then exactly one release', function (): void {
    [$runId, $last] = joinOpenWaitDbParallelAtJoin();
    $dir = joinOpenWaitDbBarrierDir();
    $stepRows = joinOpenWaitDbStepRows($runId);

    [$signaller, $branch] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        joinOpenWaitDbHoldingSignaller($runId, 'approval', $dir),
        joinOpenWaitDbBranchWorker($runId, $last, $dir, 'signaller-holds-wait'),
    ]);

    expect($signaller)->toBe(['outcome' => 'accepted', 'contended' => true, 'locks' => null], json_encode($signaller))
        ->and($branch)->toBe(['outcome' => 'completed'])
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($stepRows + 1);

    (new AdvanceDurableSwarm($runId, 3))->handle(app(DurableSwarmManager::class));

    expect(joinOpenWaitDbRunStatus($runId))->toBe('completed');
});

test('the last branch reading the wait open while a signal closes it: the branch is refused, the signal releases the join once', function (): void {
    [$runId, $last] = joinOpenWaitDbParallelAtJoin();
    $dir = joinOpenWaitDbBarrierDir();
    $stepRows = joinOpenWaitDbStepRows($runId);

    [$branch, $signaller] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        joinOpenWaitDbHoldingBranch($runId, $last, $dir),
        joinOpenWaitDbSignaller($runId, 'approval', $dir, 'branch-holds-waits'),
    ]);

    expect($branch)->toBe(['outcome' => 'completed', 'released' => false, 'contended' => true, 'locks' => null], json_encode($branch))
        ->and($signaller)->toBe(['outcome' => 'accepted'])
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($stepRows + 1);
});

test('two signals closing the last two waits at a join at once neither deadlock nor release the join twice', function (): void {
    [$runId] = joinOpenWaitDbParallelAtJoin(['first', 'second'], leaveLastBranch: false);
    $dir = joinOpenWaitDbBarrierDir();
    $stepRows = joinOpenWaitDbStepRows($runId);

    [$first, $second] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        joinOpenWaitDbRunHoldingSignaller($runId, 'first', $dir),
        joinOpenWaitDbSignaller($runId, 'second', $dir, 'first-holds-run'),
    ]);

    expect($first)->toBe(['outcome' => 'accepted', 'contended' => true, 'locks' => null], json_encode($first))
        ->and($second)->toBe(['outcome' => 'accepted'], json_encode($second))
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($stepRows + 1);
});

test('a wait timing out while the last branch asks for the join: the join waits for the published outcome, then the continuation completes', function (): void {
    joinOpenWaitDbFakeCoordinator(joinOpenWaitDbPlan());
    $runId = 'join-open-wait-db-timeout-'.bin2hex(random_bytes(4));
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, RunContext::from('queued-hierarchical-task', $runId)->toQueuePayload()))->handle(app(SwarmRunner::class));
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'approval', timeoutSeconds: 60);
    DB::table('swarm_durable_waits')->where('run_id', $runId)->update(['timeout_at' => now('UTC')->subMinute()]);
    $branches = app(DurableRunStore::class)->branchesFor($runId, 'parallel_node');
    $last = (string) end($branches)['branch_id'];
    joinOpenWaitDbMarkBranchesCompleted($runId, $last);
    $dir = joinOpenWaitDbBarrierDir();

    [$sweep, $branch] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        joinOpenWaitDbHoldingTimeoutSweep($dir),
        joinOpenWaitDbBranchWorker($runId, $last, $dir, 'sweep-holds-wait'),
    ]);

    expect($sweep)->toBe(['outcome' => 'swept', 'contended' => true, 'locks' => null], json_encode($sweep))
        ->and($branch)->toBe(['outcome' => 'completed'])
        ->and(app(ContextStore::class)->find($runId)['metadata']['durable_wait_outcomes']['approval']['status'])->toBe('timed_out')
        ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
        ->and(joinOpenWaitDbResumeRows($runId))->toBe(1);

    (new ResumeQueuedHierarchicalSwarm($runId))->handle(app(QueuedHierarchicalCoordinator::class));

    expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('completed');
});

test('a signal inside an application transaction whose snapshot predates the last branch still releases the join after its commit', function (): void {
    [$runId, $last] = joinOpenWaitDbParallelAtJoin();
    $dir = joinOpenWaitDbBarrierDir();
    $stepRows = joinOpenWaitDbStepRows($runId);

    [$signaller, $branch] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        joinOpenWaitDbSnapshotSignaller($runId, 'approval', $dir),
        joinOpenWaitDbBranchWorker($runId, $last, $dir, 'snapshot-taken'),
    ]);

    expect($branch)->toBe(['outcome' => 'completed'])
        ->and($signaller)->toBe([
            'active_in_snapshot' => 1,
            'accepted' => true,
            'before_commit' => ['status' => 'waiting', 'step_rows' => $stepRows],
        ])
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($stepRows + 1);
});

test('a last wait closing while the run fans out waits for the fan-out and leaves the run waiting on its branches', function (): void {
    // The branch rows a real fan-out of this swarm writes, taken from a template run.
    $template = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
    (new AdvanceDurableSwarm($template, 0))->handle(app(DurableSwarmManager::class));

    // The target: a run waiting on an application wait, not yet fanned out.
    $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
    app(DurableSwarmManager::class)->wait($runId, 'approval');
    $branchRows = DB::table('swarm_durable_branches')->where('run_id', $template)->get()
        ->map(function (object $row) use ($runId): array {
            $row = (array) $row;
            unset($row['id']);
            $row['run_id'] = $runId;

            return $row;
        })
        ->all();
    $stepRows = joinOpenWaitDbStepRows($runId);
    $dir = joinOpenWaitDbBarrierDir();

    [$fanOut, $signaller] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        joinOpenWaitDbHoldingFanOut($runId, $branchRows, $dir),
        joinOpenWaitDbSignaller($runId, 'approval', $dir, 'fanout-holds-run'),
    ]);

    // The closer read the run after the fan-out committed: it is at its branch
    // boundary with branches still to run, so it stays waiting and nothing is queued.
    expect($fanOut)->toBe(['outcome' => 'committed'], json_encode($fanOut))
        ->and($signaller)->toBe(['outcome' => 'accepted'])
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($stepRows);
});

test('closing the last wait never waits on branch rows, so it cannot deadlock with a branch retry writing its outbox row', function (): void {
    // Every branch terminal while the wait is open; a retry-like worker holds them all.
    [$runId, $last] = joinOpenWaitDbParallelAtJoin(leaveLastBranch: false);
    $stepRows = joinOpenWaitDbStepRows($runId);
    $dir = joinOpenWaitDbBarrierDir();

    [$retry, $signaller] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        joinOpenWaitDbHoldingRetry($runId, $last, $dir),
        joinOpenWaitDbSignallerAfter($runId, 'approval', $dir, 'retry-holds-branches'),
    ]);

    expect($retry)->toBe(['outcome' => 'committed', 'closer_blocked_on_branches' => false], json_encode($retry))
        ->and($signaller)->toBe(['outcome' => 'accepted'], json_encode($signaller))
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($stepRows + 1);
});

/*
 * The lease guards on the real engines (MYSQL-QH-NODE-LEASE, MYSQL-LEASE). MySQL
 * reports changed rows, not matched rows, so a lease-held write whose values all
 * equal the stored ones returns 0; the stores then re-read the guard to tell that
 * no-op from a lost lease. Time is frozen so the no-op is exact, not a same-second
 * coincidence. On Postgres a held lease never returns 0, so the no-op tests pass
 * either way there; the lost-lease tests hold on both engines.
 */

function joinOpenWaitDbLeasedDurableRun(): array
{
    $runId = FakeHierarchicalFullSwarm::make()->dispatchDurable('lease-guard-task')->runId;
    $token = app(DurableRunStore::class)->acquireLease($runId, 0, 300);
    expect($token)->not->toBeNull();

    return [$runId, (string) $token];
}

function joinOpenWaitDbLeasedHistoryRun(): array
{
    $runId = 'join-open-wait-db-history-lease-'.bin2hex(random_bytes(4));
    $acquisition = app(RunHistoryStore::class)->acquireQueuedRun($runId, FakeHierarchicalFullSwarm::class, 'hierarchical', RunContext::from('lease-guard-task', $runId), [], 3600, 300);
    expect($acquisition->outcome)->toBe('fresh');

    return [$runId, (string) $acquisition->executionToken];
}

/**
 * Run a write and count the locking re-reads of the lease guard it made on the table.
 * MySQL reaches the re-read only on a write that changed no row; Postgres reports
 * matched rows, so a lease-held write never reaches it there.
 */
function joinOpenWaitDbLeaseRereads(string $table, Closure $write): int
{
    $rereads = 0;
    DB::listen(function ($query) use ($table, &$rereads): void {
        $sql = strtolower($query->sql);

        if (str_starts_with($sql, 'select') && str_contains($sql, 'for update') && str_contains($sql, $table)) {
            $rereads++;
        }
    });
    $write();

    return $rereads;
}

function joinOpenWaitDbExpectedRereads(): int
{
    return DB::connection()->getDriverName() === 'mysql' ? 1 : 0;
}

function joinOpenWaitDbSecondConnection(): Connection
{
    config()->set('database.connections.lease-guard-other', config('database.connections.'.DB::getDefaultConnection()));

    return DB::connection('lease-guard-other');
}

/**
 * Open a transaction on the stores' connection and take its read snapshot of the run
 * row, as a worker does when it reads the run before its guarded write. On MySQL the
 * transaction is REPEATABLE READ (its default), so a plain read inside it keeps
 * showing the row as it was here.
 */
function joinOpenWaitDbSnapshotOf(string $table, string $runId): void
{
    if (DB::connection()->getDriverName() === 'mysql') {
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }

    DB::beginTransaction();
    expect(DB::table($table)->where('run_id', $runId)->value('execution_token'))->not->toBeNull();
}

test('a durable lease-held write that changes nothing is not a lost lease, at the node-state write and at markRunning', function (): void {
    Carbon::setTestNow(Carbon::now('UTC')->startOfSecond());
    [$runId, $token] = joinOpenWaitDbLeasedDurableRun();
    $store = app(DurableRunStore::class);

    $store->markRunning($runId, $token, 0);
    $nodeId = DB::table('swarm_durable_runs')->where('run_id', $runId)->value('current_node_id');
    expect($nodeId)->not->toBeNull();

    // The same write again, in the same second: every value equals the stored one, so on
    // MySQL it changes no row and the guard is re-read.
    expect(joinOpenWaitDbLeaseRereads('swarm_durable_runs', fn () => $store->markRunning($runId, $token, 0)))
        ->toBe(joinOpenWaitDbExpectedRereads());

    // A new lease in the same second on a run whose node is unchanged: acquireLease()'s
    // node-state write is the no-op MYSQL-QH-NODE-LEASE named.
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['leased_until' => Carbon::now('UTC')->subSecond()]);
    $next = null;
    expect(joinOpenWaitDbLeaseRereads('swarm_durable_runs', function () use ($store, $runId, &$next): void {
        $next = $store->acquireLease($runId, 0, 300);
    }))->toBe(joinOpenWaitDbExpectedRereads());

    expect($next)->not->toBeNull()->not->toBe($token);
    $run = DB::table('swarm_durable_runs')->where('run_id', $runId)->first();
    expect($run->status)->toBe('running')
        ->and($run->execution_token)->toBe($next)
        ->and($run->current_node_id)->toBe($nodeId);

    Carbon::setTestNow();
});

test('a durable lease that was taken or has expired is still refused', function (): void {
    [$runId, $token] = joinOpenWaitDbLeasedDurableRun();
    $store = app(DurableRunStore::class);

    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['execution_token' => 'another-worker']);
    expect(fn () => $store->markRunning($runId, $token, 0))->toThrow(LostDurableLeaseException::class);

    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['execution_token' => $token, 'leased_until' => Carbon::now('UTC')->subMinute()]);
    expect(fn () => $store->markRunning($runId, $token, 0))->toThrow(LostDurableLeaseException::class);

    expect(DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status'))->toBe('pending');
});

test('a durable lease taken by another worker after this transaction read the run is refused, not confirmed from the stale snapshot', function (): void {
    [$runId, $token] = joinOpenWaitDbLeasedDurableRun();
    $other = joinOpenWaitDbSecondConnection();
    joinOpenWaitDbSnapshotOf('swarm_durable_runs', $runId);

    try {
        $other->table('swarm_durable_runs')->where('run_id', $runId)->update(['execution_token' => 'another-worker']);

        expect(fn () => app(DurableRunStore::class)->markRunning($runId, $token, 0))->toThrow(LostDurableLeaseException::class);
        expect(DB::table('swarm_durable_runs')->where('run_id', $runId)->lockForUpdate()->first(['status', 'execution_token']))
            ->toEqual((object) ['status' => 'pending', 'execution_token' => 'another-worker']);
    } finally {
        DB::rollBack();
        $other->disconnect();
    }
});

test('a history lease-held step write that changes nothing is not a lost lease', function (): void {
    Carbon::setTestNow(Carbon::now('UTC')->startOfSecond());
    [$runId, $token] = joinOpenWaitDbLeasedHistoryRun();
    $history = app(RunHistoryStore::class);

    // Same TTL and lease as the acquisition, in the same second: the renewal writes the
    // stored expires_at, leased_until and updated_at back unchanged.
    $history->recordStep($runId, new SwarmStep(FakeWriter::class, 'in', 'first', metadata: ['index' => 0]), 3600, $token, 300);
    expect(joinOpenWaitDbLeaseRereads('swarm_run_histories', fn () => $history->recordStep($runId, new SwarmStep(FakeEditor::class, 'first', 'second', metadata: ['index' => 1]), 3600, $token, 300)))
        ->toBe(joinOpenWaitDbExpectedRereads());

    expect(DB::table('swarm_run_steps')->where('run_id', $runId)->orderBy('step_index')->pluck('agent_class')->all())
        ->toBe([FakeWriter::class, FakeEditor::class])
        ->and(DB::table('swarm_run_histories')->where('run_id', $runId)->value('execution_token'))->toBe($token);

    Carbon::setTestNow();
});

test('a history lease that was taken or has expired is still refused, and its step is not written', function (): void {
    [$runId, $token] = joinOpenWaitDbLeasedHistoryRun();
    $history = app(RunHistoryStore::class);

    DB::table('swarm_run_histories')->where('run_id', $runId)->update(['execution_token' => 'another-worker']);
    expect(fn () => $history->recordStep($runId, new SwarmStep(FakeWriter::class, 'in', 'out', metadata: ['index' => 0]), 3600, $token, 300))
        ->toThrow(LostSwarmLeaseException::class);

    DB::table('swarm_run_histories')->where('run_id', $runId)->update(['execution_token' => $token, 'leased_until' => Carbon::now('UTC')->subMinute()]);
    expect(fn () => $history->recordStep($runId, new SwarmStep(FakeWriter::class, 'in', 'out', metadata: ['index' => 0]), 3600, $token, 300))
        ->toThrow(LostSwarmLeaseException::class);

    expect(DB::table('swarm_run_steps')->where('run_id', $runId)->count())->toBe(0);
});

test('a history lease taken by another worker after this transaction read the run is refused, not confirmed from the stale snapshot', function (): void {
    [$runId, $token] = joinOpenWaitDbLeasedHistoryRun();
    $other = joinOpenWaitDbSecondConnection();
    joinOpenWaitDbSnapshotOf('swarm_run_histories', $runId);

    try {
        $other->table('swarm_run_histories')->where('run_id', $runId)->update(['execution_token' => 'another-worker']);

        expect(fn () => app(RunHistoryStore::class)->recordStep($runId, new SwarmStep(FakeWriter::class, 'in', 'out', metadata: ['index' => 0]), 3600, $token, 300))
            ->toThrow(LostSwarmLeaseException::class);
        // A step row this transaction wrote would be visible to it without a lock.
        expect(DB::table('swarm_run_steps')->where('run_id', $runId)->count())->toBe(0);
    } finally {
        DB::rollBack();
        $other->disconnect();
    }
});

/**
 * Leave a terminal branch boundary for recovery, without invoking a wait closer.
 */
function joinOpenWaitDbRecoveryCandidate(string $profile): string
{
    if ($profile === 'qh') {
        joinOpenWaitDbFakeCoordinator(joinOpenWaitDbPlan());
        $runId = 'join-recovery-db-'.bin2hex(random_bytes(6));
        (new InvokeSwarm(FakeHierarchicalFullSwarm::class, RunContext::from('recovery-task', $runId)->toQueuePayload()))->handle(app(SwarmRunner::class));
        app(DurableSwarmManager::class)->wait($runId, 'approval');
    } else {
        [$runId] = joinOpenWaitDbParallelAtJoin(leaveLastBranch: false);
    }

    joinOpenWaitDbMarkBranchesCompleted($runId);
    DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', 'approval')->delete();
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update([
        'updated_at' => now('UTC')->subMinutes(10),
        'queue_connection' => 'durable-test',
        'queue_name' => 'recovered-joins',
    ]);

    return $runId;
}

test('recovery commits the join and continuation intent together on the real engine', function (string $profile): void {
    config()->set('swarm.durable.eager_dispatch', false);
    $runId = joinOpenWaitDbRecoveryCandidate($profile);
    $manager = app(DurableSwarmManager::class);
    $step = (int) $manager->find($runId)['next_step_index'];
    $baseline = (int) DB::table('swarm_durable_outbox')->max('id');
    Queue::fake();

    $recovered = $manager->recover(runId: $runId);
    $rows = DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('id', '>', $baseline);
    expect($rows->count())->toBe(1)
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and($recovered)->toContain($runId);
    $row = $rows->first();
    expect($row->dispatch_type)->toBe($profile === 'qh' ? 'queued_resume' : 'step')
        ->and($row->queue_connection)->toBe('durable-test')
        ->and($row->queue_name)->toBe('recovered-joins');
    if ($profile !== 'qh') {
        expect((int) json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)['step_index'])->toBe($step);
    }
    $class = $profile === 'qh' ? ResumeQueuedHierarchicalSwarm::class : AdvanceDurableSwarm::class;
    $matches = fn ($job): bool => $job->runId === $runId && ($profile === 'qh' || $job->stepIndex === $step);
    Queue::assertNotPushed($class, $matches);

    Artisan::call('swarm:relay');
    $jobs = Queue::pushed($class, $matches);
    expect($jobs)->toHaveCount(1);
    $job = $jobs->first();
    expect($job->connection)->toBe('durable-test')->and($job->queue)->toBe('recovered-joins');
    $job->handle(app($profile === 'qh' ? QueuedHierarchicalCoordinator::class : DurableSwarmManager::class));
    expect(joinOpenWaitDbRunStatus($runId))->toBe('completed');
})->with(['parallel', 'qh']);

test('a failed continuation insert rolls back the join release on the real engine', function (string $profile): void {
    config()->set('swarm.durable.eager_dispatch', false);
    $fail = false;
    $realOutbox = app(DurableOutbox::class);
    $outbox = Mockery::mock($realOutbox);
    foreach (['enqueueStep', 'enqueueQueuedResume'] as $method) {
        $outbox->shouldReceive($method)->andReturnUsing(function (...$args) use (&$fail, $realOutbox, $method) {
            if ($fail) {
                throw new RuntimeException('recovery outbox unavailable');
            }

            return $realOutbox->{$method}(...$args);
        });
    }
    app()->instance(DurableOutbox::class, $outbox);
    $runId = joinOpenWaitDbRecoveryCandidate($profile);
    $baseline = (int) DB::table('swarm_durable_outbox')->max('id');
    $fail = true;
    Queue::fake();

    $recovered = app(DurableSwarmManager::class)->recover(runId: $runId);

    expect(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('id', '>', $baseline)->count())->toBe(0)
        ->and($recovered)->not->toContain($runId);
    Queue::assertNothingPushed();
})->with(['parallel', 'qh']);
