<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJoinRelease;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\BranchWaitPayload;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FailingPromptAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalParallelFailBranchSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\JoinBoundaryChildSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\JoinBoundaryWaitSwarm;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\JoinOpenWait\JoinOpenWaitWorker;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\LockBarrier;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
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
 * Opens an application transaction and takes its snapshot before the run fans out (it
 * sees no branch rows), waits for the fan-out to commit, counts the branch rows again
 * inside the transaction — still none under MySQL's REPEATABLE READ, all of them under
 * Postgres' READ COMMITTED — then signals the wait in that transaction. Reports what
 * it saw before its commit. The isolation level is set, not left to the server default.
 */
function joinOpenWaitDbStaleSnapshotSignaller(string $runId, string $wait, string $dir): Closure
{
    return static function () use ($runId, $wait, $dir): array {
        JoinOpenWaitWorker::boot();
        $branchRows = fn (): int => DB::table('swarm_durable_branches')->where('run_id', $runId)->count();
        $stepRows = fn (): int => DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'step')->count();
        $mysql = DB::connection()->getDriverName() === 'mysql';

        if ($mysql) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        DB::beginTransaction();

        if (! $mysql) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        $beforeFanOut = $branchRows();
        LockBarrier::signal($dir, 'snapshot-taken');
        LockBarrier::await($dir, 'fanout-done');
        $afterFanOut = $branchRows();

        try {
            $accepted = app(DurableSwarmManager::class)->signal($runId, $wait)->accepted;
            $beforeCommit = ['status' => DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status'), 'step_rows' => $stepRows()];
            DB::commit();
        } catch (Throwable $e) {
            try {
                DB::rollBack();
            } catch (Throwable) {
            }

            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['branch_rows_before_fan_out' => $beforeFanOut, 'branch_rows_after_fan_out' => $afterFanOut, 'accepted' => $accepted, 'before_commit' => $beforeCommit];
    };
}

/**
 * The fan-out of a leased top-level parallel run, through the store's own
 * waitForBranches(), once the other worker has taken its snapshot. It commits before
 * that worker touches the run, so nothing here waits on a lock.
 */
function joinOpenWaitDbFanOutAfter(string $runId, string $token, string $dir, string $after): Closure
{
    return static function () use ($runId, $token, $dir, $after): array {
        JoinOpenWaitWorker::boot();
        LockBarrier::await($dir, $after);

        try {
            app(DurableRunStore::class)->waitForBranches($runId, new BranchWaitPayload(
                executionToken: $token,
                nextStepIndex: 3,
                parentNodeId: 'parallel',
                context: RunContext::fromPayload((array) app(ContextStore::class)->find($runId)),
                ttlSeconds: 3600,
                branches: [
                    ['branch_id' => 'parallel:0', 'step_index' => 0, 'node_id' => null, 'agent_class' => FakeResearcher::class, 'parent_node_id' => 'parallel', 'input' => 'parallel-durable-task', 'metadata' => ['parallel_branch_index' => 0]],
                    ['branch_id' => 'parallel:1', 'step_index' => 1, 'node_id' => null, 'agent_class' => FakeWriter::class, 'parent_node_id' => 'parallel', 'input' => 'parallel-durable-task', 'metadata' => ['parallel_branch_index' => 1]],
                    ['branch_id' => 'parallel:2', 'step_index' => 2, 'node_id' => null, 'agent_class' => FakeEditor::class, 'parent_node_id' => 'parallel', 'input' => 'parallel-durable-task', 'metadata' => ['parallel_branch_index' => 2]],
                ],
            ));

            return ['outcome' => 'committed'];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        } finally {
            LockBarrier::signal($dir, 'fanout-done');
        }
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

function joinCreateHoldingCreator(string $runId, string $dir, ?int $timeoutSeconds = null): Closure
{
    return static function () use ($runId, $dir, $timeoutSeconds): array {
        $store = JoinOpenWaitWorker::bootHooked();
        $me = LockBarrier::connectionId();
        $contended = false;
        $store->beforeSync = static function () use ($store, $dir, $me, &$contended): void {
            $store->beforeSync = null;
            LockBarrier::signal($dir, 'creator-ready');
            $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'join-id'), $me);
        };
        try {
            app(DurableSwarmManager::class)->wait($runId, 'new-obligation', timeoutSeconds: $timeoutSeconds);

            return ['outcome' => 'created', 'contended' => $contended];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

function joinCreateReleaser(string $runId, string $dir, bool $hold, bool $reportAttempts = false): Closure
{
    return static function () use ($runId, $dir, $hold, $reportAttempts): array {
        $store = JoinOpenWaitWorker::bootHooked();
        $contended = null;
        if ($hold) {
            $store->afterJoinCheck = static function (string $id, bool $released) use ($store, $dir, &$contended): void {
                $store->afterJoinCheck = null;
                if ($released) {
                    LockBarrier::signal($dir, 'join-ready');
                    $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'creator-id'), LockBarrier::connectionId());
                }
            };
        } else {
            LockBarrier::await($dir, 'creator-ready');
            LockBarrier::signal($dir, 'join-id', (string) LockBarrier::connectionId());
        }
        try {
            $released = app(DurableJoinRelease::class)->releaseIfReady($store->find($runId));

            return array_merge(['outcome' => 'finished', 'released' => $released, 'contended' => $contended], $reportAttempts ? ['run_lock_attempts' => $store->runLockAttempts] : []);
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

function joinCreateBeforeInsert(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        $store = JoinOpenWaitWorker::bootHooked();
        $contended = false;
        $store->afterRunLock = static function () use ($store, $dir, &$contended): void {
            $store->afterRunLock = null;
            LockBarrier::signal($dir, 'creator-ready');
            $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'join-id'), LockBarrier::connectionId());
        };
        try {
            $store->createWait($runId, 'before-insert');

            return ['outcome' => 'created', 'contended' => $contended, 'run_lock_attempts' => $store->runLockAttempts];
        } catch (SwarmException $e) {
            return ['outcome' => 'rejected', 'contended' => $contended, 'run_lock_attempts' => $store->runLockAttempts];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

function joinCreateLateCreator(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        LockBarrier::await($dir, 'join-ready');
        LockBarrier::signal($dir, 'creator-id', (string) LockBarrier::connectionId());
        try {
            // A prior ordinary read must not make the released-join decision stale
            // on MySQL's REPEATABLE READ.
            DB::connection()->transaction(function () use ($runId): void {
                DB::table('swarm_contexts')->where('run_id', $runId)->first();
                app(DurableSwarmManager::class)->wait($runId, 'too-late');
            }, 3);

            return ['outcome' => 'created'];
        } catch (SwarmException $e) {
            return ['outcome' => 'rejected', 'join_error' => str_contains($e->getMessage(), 'join')];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

function joinCreateDeclaredFanout(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        $manager = app(DurableSwarmManager::class);
        $manager->afterStepCheckpointForTesting(static function () use ($dir): void {
            LockBarrier::signal($dir, 'fanout-committed');
            LockBarrier::await($dir, 'fast-join-done');
        });
        try {
            (new AdvanceDurableSwarm($runId, 1))->handle($manager);

            return ['outcome' => 'checkpointed', 'waits' => count(app(DurableRunStore::class)->waits($runId))];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

function joinCreateFastDeclaredJoin(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        LockBarrier::await($dir, 'fanout-committed');
        try {
            DB::table('swarm_durable_branches')->where('run_id', $runId)->update([
                'status' => 'completed', 'output' => 'branch-output', 'finished_at' => now('UTC'),
                'execution_token' => null, 'leased_until' => null,
            ]);
            $released = app(DurableJoinRelease::class)->releaseIfReady(app(DurableRunStore::class)->find($runId));

            return ['outcome' => 'finished', 'released' => $released];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        } finally {
            LockBarrier::signal($dir, 'fast-join-done');
        }
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

test('a new wait winning the run lock blocks join creation without a deadlock abort (#614)', function (): void {
    [$runId] = joinOpenWaitDbParallelAtJoin([], leaveLastBranch: false);
    $dir = joinOpenWaitDbBarrierDir();
    $before = joinOpenWaitDbStepRows($runId);
    [$creator, $join] = app(ConcurrencyManager::class)->driver('process')->run([
        joinCreateHoldingCreator($runId, $dir),
        joinCreateReleaser($runId, $dir, false),
    ]);

    expect($creator)->toBe(['outcome' => 'created', 'contended' => true], json_encode($creator))
        ->and($join)->toBe(['outcome' => 'finished', 'released' => false, 'contended' => null], json_encode($join))
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($before)
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('status', 'waiting')->count())->toBe(1);
});

test('a join winning the run lock rejects new wait creation even with an older outer snapshot (#614)', function (): void {
    [$runId] = joinOpenWaitDbParallelAtJoin([], leaveLastBranch: false);
    $dir = joinOpenWaitDbBarrierDir();
    $before = joinOpenWaitDbStepRows($runId);
    [$join, $creator] = app(ConcurrencyManager::class)->driver('process')->run([
        joinCreateReleaser($runId, $dir, true),
        joinCreateLateCreator($runId, $dir),
    ]);

    expect($join)->toBe(['outcome' => 'finished', 'released' => true, 'contended' => true], json_encode($join))
        ->and($creator)->toBe(['outcome' => 'rejected', 'join_error' => true], json_encode($creator))
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($before + 1)
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->count())->toBe(0);
});

test('a creator holding the run before insert serializes with the empty-wait join read (#614)', function (): void {
    [$runId] = joinOpenWaitDbParallelAtJoin([], leaveLastBranch: false);
    app(DurableRunStore::class)->createWait($runId, 'closed-before-race');
    DB::table('swarm_durable_waits')->where('run_id', $runId)->update(['status' => 'timed_out', 'finished_at' => now('UTC')]);
    $dir = joinOpenWaitDbBarrierDir();
    $before = joinOpenWaitDbStepRows($runId);
    [$creator, $join] = app(ConcurrencyManager::class)->driver('process')->run([
        joinCreateBeforeInsert($runId, $dir),
        joinCreateReleaser($runId, $dir, false, true),
    ]);

    expect($creator['outcome'])->toBeIn(['created', 'rejected'], json_encode($creator))
        ->and($creator['contended'])->toBeTrue()
        ->and($join['outcome'])->toBe('finished', json_encode($join));
    $open = DB::table('swarm_durable_waits')->where('run_id', $runId)->where('status', 'waiting')->count();
    expect([$open, joinOpenWaitDbStepRows($runId) - $before])->toBeIn([[1, 0], [0, 1]]);
});

test('fast branches cannot bypass the declared boundary committed with fanout (#614)', function (string $swarm): void {
    joinOpenWaitDbFakeCoordinator(joinOpenWaitDbPlan());
    $runId = $swarm::make()->dispatchDurable('declared-boundary')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    $before = joinOpenWaitDbStepRows($runId);
    $dir = joinOpenWaitDbBarrierDir();
    [$fanout, $join] = app(ConcurrencyManager::class)->driver('process')->run([
        joinCreateDeclaredFanout($runId, $dir),
        joinCreateFastDeclaredJoin($runId, $dir),
    ]);
    expect($fanout)->toBe(['outcome' => 'checkpointed', 'waits' => 1], json_encode($fanout))
        ->and($join)->toBe(['outcome' => 'finished', 'released' => false], json_encode($join))
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($before);
})->with([
    JoinBoundaryWaitSwarm::class,
    JoinBoundaryChildSwarm::class,
]);

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

test('a last wait closed inside an application transaction whose snapshot predates the fan-out leaves the run waiting on its branches (#617)', function (): void {
    // A step that holds its lease and has an open application wait, about to fan out.
    $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
    $store = app(DurableRunStore::class);
    $token = (string) $store->acquireLease($runId, 0, 300);
    $store->markRunning($runId, $token, 0);
    app(DurableSwarmManager::class)->wait($runId, 'approval');
    $stepRows = joinOpenWaitDbStepRows($runId);
    $dir = joinOpenWaitDbBarrierDir();

    [$signaller, $fanOut] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        joinOpenWaitDbStaleSnapshotSignaller($runId, 'approval', $dir),
        joinOpenWaitDbFanOutAfter($runId, $token, $dir, 'snapshot-taken'),
    ]);

    // Under MySQL's REPEATABLE READ the closer's transaction cannot see the branch
    // rows the fan-out committed; the locked run row is what tells it the run fanned
    // out. Postgres reads them, and the same outcome is required there.
    expect($fanOut)->toBe(['outcome' => 'committed'], json_encode($fanOut))
        ->and($signaller)->toBe([
            'branch_rows_before_fan_out' => 0,
            'branch_rows_after_fan_out' => DB::connection()->getDriverName() === 'mysql' ? 0 : 3,
            'accepted' => true,
            'before_commit' => ['status' => 'waiting', 'step_rows' => $stepRows],
        ], json_encode($signaller))
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($stepRows)
        ->and(DB::table('swarm_durable_branches')->where('run_id', $runId)->where('parent_node_id', 'parallel')->where('status', 'pending')->count())->toBe(3);

    // The run it kept waiting is still released to its join, once: by the last branch.
    $manager = app(DurableSwarmManager::class);

    foreach (['parallel:0', 'parallel:1'] as $branchId) {
        (new AdvanceDurableBranch($runId, $branchId))->handle($manager);
    }

    expect(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($stepRows);

    (new AdvanceDurableBranch($runId, 'parallel:2'))->handle($manager);

    expect(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($stepRows + 1);
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

/**
 * A queued-hierarchical run waiting at its join with every branch written terminal
 * straight to the table, so nothing has released the join yet.
 */
function strayResumeDbAtJoin(): string
{
    $runId = strayResumeDbInvoke('qh-stray-db-');
    joinOpenWaitDbMarkBranchesCompleted($runId);

    expect(joinOpenWaitDbRunStatus($runId))->toBe('waiting');

    return $runId;
}

/**
 * Invokes a queued-hierarchical run to its first fan-out; it waits at its join with
 * its branches still to run.
 */
function strayResumeDbInvoke(string $prefix): string
{
    joinOpenWaitDbFakeCoordinator(joinOpenWaitDbPlan());
    $runId = $prefix.bin2hex(random_bytes(4));
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, RunContext::from('queued-hierarchical-task', $runId)->toQueuePayload()))->handle(app(SwarmRunner::class));

    return $runId;
}

function strayResumeDbAdvanceBranches(string $runId): void
{
    $manager = app(DurableSwarmManager::class);

    foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel_node') as $branch) {
        (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle($manager);
    }
}

function strayResumeDbHistory(string $runId): object
{
    return DB::table('swarm_run_histories')->where('run_id', $runId)->first();
}

/**
 * Releases the run to its join and holds the release — run row written, outbox row
 * not yet — until the resume worker is observed blocked on it.
 */
function strayResumeDbHoldingRelease(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        $store = JoinOpenWaitWorker::bootHooked();
        $me = LockBarrier::connectionId();
        $contended = false;
        $locks = null;
        $store->afterJoinCheck = static function (string $runId, bool $released) use ($dir, $me, &$contended, &$locks): void {
            LockBarrier::signal($dir, 'release-holds-run');
            $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'resume-id'), $me);
            $locks = $contended ? null : LockBarrier::lockWaitSnapshot();
        };

        try {
            $released = app(DurableJoinRelease::class)->releaseIfReady(app(DurableRunStore::class)->find($runId));
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => $released ? 'released' : 'refused', 'contended' => $contended, 'locks' => $locks];
    };
}

/**
 * Plays the continuation's next fan-out in the write order of
 * QueuedHierarchicalDurableCoordinator::enter(), which it must stay in step with:
 * waitForBranches() writes the durable run row 'waiting' first, then
 * syncDurableState() writes the history row 'waiting' with the join flag. The history
 * write waits until the resume worker is observed blocked on the run row.
 */
function strayResumeDbHoldingFanOut(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        $me = LockBarrier::connectionId();

        try {
            DB::beginTransaction();
            DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['status' => 'waiting', 'updated_at' => now('UTC')]);
            LockBarrier::signal($dir, 'fanout-holds-run');

            if (! LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'resume-id'), $me)) {
                $locks = LockBarrier::lockWaitSnapshot();
                DB::rollBack();

                return ['outcome' => 'no-contention', 'locks' => $locks];
            }

            $metadata = json_decode((string) DB::table('swarm_run_histories')->where('run_id', $runId)->value('metadata'), true, flags: JSON_THROW_ON_ERROR);
            $metadata['queue_hierarchical_waiting_parallel'] = true;
            DB::table('swarm_run_histories')->where('run_id', $runId)->update([
                'status' => 'waiting',
                'execution_token' => null,
                'leased_until' => null,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => 'committed'];
    };
}

/**
 * Delivers the queued resume once the other worker holds the run row. Reports whether
 * this delivery ran the continuation itself.
 */
function strayResumeDbResumer(string $runId, string $dir, string $after): Closure
{
    return static function () use ($runId, $dir, $after): array {
        JoinOpenWaitWorker::boot();
        LockBarrier::signal($dir, 'resume-id', (string) LockBarrier::connectionId());
        LockBarrier::await($dir, $after);

        try {
            $response = app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId);
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => $response !== null ? 'completed' : 'no-op'];
    };
}

test('queued-hierarchical: a duplicate resume while an open wait holds the join changes nothing; the genuine resume completes the run (#610)', function (): void {
    $runId = strayResumeDbInvoke('qh-stray-db-wait-');
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'approval');
    strayResumeDbAdvanceBranches($runId);

    expect(app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId))->toBeNull()
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(strayResumeDbHistory($runId)->status)->toBe('waiting');

    expect($manager->signal($runId, 'approval')->accepted)->toBeTrue()
        ->and(joinOpenWaitDbResumeRows($runId))->toBe(1);

    expect(app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId))->not->toBeNull()
        ->and(app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId))->toBeNull()
        ->and(strayResumeDbHistory($runId)->status)->toBe('completed');
});

test('queued-hierarchical: a duplicate resume while branches are still active does not fail the run; it then completes (#610)', function (): void {
    $runId = strayResumeDbInvoke('qh-stray-db-active-');

    expect(app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId))->toBeNull()
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(strayResumeDbHistory($runId)->status)->toBe('waiting');

    strayResumeDbAdvanceBranches($runId);

    expect(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and(app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId))->not->toBeNull()
        ->and(strayResumeDbHistory($runId)->status)->toBe('completed');
});

test('queued-hierarchical: a resume delivered while its join release is in flight waits for it and runs the continuation (#610)', function (): void {
    $runId = strayResumeDbAtJoin();
    $dir = joinOpenWaitDbBarrierDir();

    [$releaser, $resumer] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        strayResumeDbHoldingRelease($runId, $dir),
        strayResumeDbResumer($runId, $dir, 'release-holds-run'),
    ]);

    expect($releaser)->toBe(['outcome' => 'released', 'contended' => true, 'locks' => null], json_encode($releaser))
        ->and($resumer)->toBe(['outcome' => 'completed'], json_encode($resumer))
        ->and(strayResumeDbHistory($runId)->status)->toBe('completed')
        ->and(joinOpenWaitDbResumeRows($runId))->toBe(1);

    // The release's own queued resume arrives after the continuation ran: a no-op.
    expect(app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId))->toBeNull()
        ->and(strayResumeDbHistory($runId)->status)->toBe('completed');
});

test('queued-hierarchical: a duplicate resume during the next fan-out waits on the run row, then leaves the new boundary alone (#610)', function (): void {
    $runId = strayResumeDbAtJoin();
    expect(app(DurableJoinRelease::class)->releaseIfReady(app(DurableRunStore::class)->find($runId)))->toBeTrue();

    // The genuine resume holds the continuation lease and is fanning out again.
    expect(app(RunHistoryStore::class)->acquireQueuedRunContinuationLease($runId, 3600, 300)->outcome)->toBe('fresh');
    $dir = joinOpenWaitDbBarrierDir();

    [$fanOut, $resumer] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        strayResumeDbHoldingFanOut($runId, $dir),
        strayResumeDbResumer($runId, $dir, 'fanout-holds-run'),
    ]);

    expect($fanOut)->toBe(['outcome' => 'committed'], json_encode($fanOut))
        ->and($resumer)->toBe(['outcome' => 'no-op'], json_encode($resumer))
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(strayResumeDbHistory($runId)->status)->toBe('waiting')
        ->and(json_decode((string) strayResumeDbHistory($runId)->metadata, true)['queue_hierarchical_waiting_parallel'] ?? null)->toBeTrue();
});

function joinCreateThreeWayJoin(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        $store = JoinOpenWaitWorker::bootHooked();
        $contended = null;
        $store->afterRunLock = static function () use ($store, $dir, &$contended): void {
            $store->afterRunLock = null;
            LockBarrier::signal($dir, 'closer-start', 'contend');
            $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'closer-id'), LockBarrier::connectionId());
        };
        $store->afterJoinCheck = static function () use ($dir): void {
            if (! is_file($dir.'/closer-start')) {
                LockBarrier::signal($dir, 'closer-start', 'uncontended');
            }
        };
        LockBarrier::await($dir, 'creator-ready');
        LockBarrier::signal($dir, 'join-id', (string) LockBarrier::connectionId());
        try {
            $released = app(DurableJoinRelease::class)->releaseIfReady($store->find($runId));

            return ['outcome' => 'finished', 'released' => $released, 'contended' => $contended, 'run_lock_attempts' => $store->runLockAttempts];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

function joinCreateThreeWayCloser(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        $store = JoinOpenWaitWorker::bootHooked();
        LockBarrier::signal($dir, 'closer-id', (string) LockBarrier::connectionId());
        LockBarrier::await($dir, 'closer-start');
        $store->beforeSync = static function () use ($dir): void {
            LockBarrier::signal($dir, 'closer-holds-wait');
        };
        try {
            return ['outcome' => 'finished', 'closed' => $store->releaseTimedOutWait($runId, 'new-obligation')];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

test('a newly committed wait closing behind the join run lock does not invert locks (#614 review)', function (): void {
    [$runId] = joinOpenWaitDbParallelAtJoin([], leaveLastBranch: false);
    $before = joinOpenWaitDbStepRows($runId);
    $dir = joinOpenWaitDbBarrierDir();
    [$creator, $join, $closer] = app(ConcurrencyManager::class)->driver('process')->run([
        joinCreateHoldingCreator($runId, $dir, -1),
        joinCreateThreeWayJoin($runId, $dir),
        joinCreateThreeWayCloser($runId, $dir),
    ]);
    expect($creator)->toBe(['outcome' => 'created', 'contended' => true], json_encode($creator))
        ->and($closer)->toBe(['outcome' => 'finished', 'closed' => true], json_encode($closer));
    $postgres = DB::connection()->getDriverName() === 'pgsql';
    expect($join)->toBe(['outcome' => 'finished', 'released' => false, 'contended' => $postgres ? true : null, 'run_lock_attempts' => $postgres ? 1 : 0], json_encode($join))
        ->and(joinOpenWaitDbStepRows($runId))->toBe($before);
    expect(app(DurableJoinRelease::class)->releaseIfReady(app(DurableRunStore::class)->find($runId)))->toBeTrue()
        ->and(joinOpenWaitDbStepRows($runId))->toBe($before + 1);
});

function joinCreateSnapshotBeforeFanout(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        $connection = DB::connection();
        $before = null;
        try {
            $connection->transaction(function () use ($runId, $dir, &$before): void {
                $before = DB::table('swarm_durable_branches')->where('run_id', $runId)->count();
                LockBarrier::signal($dir, 'before-fanout-snapshot');
                LockBarrier::await($dir, 'released-after-fanout');
                app(DurableSwarmManager::class)->wait($runId, 'old-snapshot');
            }, 3);

            return ['outcome' => 'created', 'branches_in_snapshot' => $before];
        } catch (SwarmException $e) {
            return ['outcome' => 'rejected', 'branches_in_snapshot' => $before];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

function joinCreateFanoutAfterSnapshot(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        LockBarrier::await($dir, 'before-fanout-snapshot');
        try {
            (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
            DB::table('swarm_durable_branches')->where('run_id', $runId)->update([
                'status' => 'completed', 'output' => 'branch-output', 'finished_at' => now('UTC'),
                'execution_token' => null, 'leased_until' => null,
            ]);

            return ['released' => app(DurableJoinRelease::class)->releaseIfReady(app(DurableRunStore::class)->find($runId))];
        } finally {
            LockBarrier::signal($dir, 'released-after-fanout');
        }
    };
}

test('a snapshot taken before fanout cannot hide the released join from wait creation (#614 review)', function (): void {
    $runId = FakeParallelSwarm::make()->dispatchDurable('before-fanout')->runId;
    $before = joinOpenWaitDbStepRows($runId);
    $dir = joinOpenWaitDbBarrierDir();
    [$creator, $fanout] = app(ConcurrencyManager::class)->driver('process')->run([
        joinCreateSnapshotBeforeFanout($runId, $dir), joinCreateFanoutAfterSnapshot($runId, $dir),
    ]);
    expect($creator)->toBe(['outcome' => 'rejected', 'branches_in_snapshot' => 0], json_encode($creator))
        ->and($fanout)->toBe(['released' => true])
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('pending')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($before + 1)
        ->and(app(DurableRunStore::class)->waits($runId))->toBe([]);
});

function joinCreateStalePositiveCloser(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        $store = app(DurableRunStore::class);
        try {
            return DB::connection()->transaction(function () use ($store, $runId, $dir): array {
                $store->waits($runId);
                LockBarrier::signal($dir, 'both-open-snapshot');
                LockBarrier::await($dir, 'first-wait-closed');

                return ['closed' => $store->releaseTimedOutWait($runId, 'second-expired')];
            });
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }
    };
}

function joinCreateFirstSnapshotCloser(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        LockBarrier::await($dir, 'both-open-snapshot');
        try {
            return ['closed' => app(DurableRunStore::class)->releaseTimedOutWait($runId, 'first-expired')];
        } finally {
            LockBarrier::signal($dir, 'first-wait-closed');
        }
    };
}

test('a stale positive wait summary cannot strand a join after both waits close (#614 review)', function (): void {
    [$runId] = joinOpenWaitDbParallelAtJoin([], leaveLastBranch: false);
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'first-expired', timeoutSeconds: -1);
    $manager->wait($runId, 'second-expired', timeoutSeconds: -1);
    $dir = joinOpenWaitDbBarrierDir();
    [$second, $first] = app(ConcurrencyManager::class)->driver('process')->run([
        joinCreateStalePositiveCloser($runId, $dir), joinCreateFirstSnapshotCloser($runId, $dir),
    ]);
    expect($first)->toBe(['closed' => true])->and($second)->toBe(['closed' => true])
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('status', 'waiting')->count())->toBe(0);
    expect(app(DurableJoinRelease::class)->releaseIfReady($manager->find($runId)))->toBeTrue();
});

function joinCreateAfterScan(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        LockBarrier::await($dir, 'empty-scan-finished');
        app(DurableRunStore::class)->createWait($runId, 'after-scan');
        LockBarrier::signal($dir, 'after-scan-created');

        return ['created' => true];
    };
}

function joinCreateHoldAfterScan(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $observed = false;
        DB::listen(static function (QueryExecuted $query) use ($dir, &$observed): void {
            if (! $observed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'swarm_durable_waits')) {
                $observed = true;
                LockBarrier::signal($dir, 'empty-scan-finished');
                LockBarrier::await($dir, 'after-scan-created');
            }
        });

        return ['released' => app(DurableJoinRelease::class)->releaseIfReady(app(DurableRunStore::class)->find($runId)), 'observed' => $observed];
    };
}

test('the generation observation precedes the empty wait scan on committed-read engines (#614 review)', function (): void {
    [$runId] = joinOpenWaitDbParallelAtJoin([], leaveLastBranch: false);
    $before = joinOpenWaitDbStepRows($runId);
    $dir = joinOpenWaitDbBarrierDir();
    [$join, $creator] = app(ConcurrencyManager::class)->driver('process')->run([
        joinCreateHoldAfterScan($runId, $dir), joinCreateAfterScan($runId, $dir),
    ]);
    expect($creator)->toBe(['created' => true])
        ->and($join)->toBe(['released' => false, 'observed' => true])
        ->and(joinOpenWaitDbRunStatus($runId))->toBe('waiting')
        ->and(joinOpenWaitDbStepRows($runId))->toBe($before);
});

function joinCreateOldEpochReleaser(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();

        return DB::connection()->transaction(function () use ($runId, $dir): array {
            $generation = (string) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('wait_generation');
            LockBarrier::signal($dir, 'old-epoch-snapshot');
            LockBarrier::await($dir, 'created-and-closed');

            return ['generation' => $generation, 'released' => app(DurableJoinRelease::class)->releaseIfReady(app(DurableRunStore::class)->find($runId))];
        });
    };
}

function joinCreateThenCloseBeforeJoin(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        LockBarrier::await($dir, 'old-epoch-snapshot');
        $store = app(DurableRunStore::class);
        $store->createWait($runId, 'already-closed', timeoutSeconds: -1);
        $closed = $store->releaseTimedOutWait($runId, 'already-closed');
        LockBarrier::signal($dir, 'created-and-closed');

        return ['closed' => $closed];
    };
}

test('a fresh attempt releases after an older generation snapshot conservatively refused (#614 review)', function (): void {
    [$runId] = joinOpenWaitDbParallelAtJoin([], leaveLastBranch: false);
    $before = joinOpenWaitDbStepRows($runId);
    $dir = joinOpenWaitDbBarrierDir();
    [$join, $closer] = app(ConcurrencyManager::class)->driver('process')->run([
        joinCreateOldEpochReleaser($runId, $dir), joinCreateThenCloseBeforeJoin($runId, $dir),
    ]);
    $mysql = DB::connection()->getDriverName() === 'mysql';
    expect($closer)->toBe(['closed' => true])
        ->and($join)->toBe(['generation' => '0', 'released' => ! $mysql]);
    expect(app(DurableJoinRelease::class)->releaseIfReady(app(DurableRunStore::class)->find($runId)))->toBe($mysql)
        ->and(joinOpenWaitDbStepRows($runId))->toBe($before + 1);
});

test('wait generation migration upgrades populated run rows on the real engines (#614 review)', function (): void {
    [$runId] = joinOpenWaitDbParallelAtJoin(['legacy-open'], leaveLastBranch: false);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_07_000001_add_wait_generation_to_swarm_durable_runs.php';
    $migration->down();
    $migration->up();
    expect((string) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('wait_generation'))->toBe('0')
        ->and(app(DurableRunStore::class)->waits($runId)[0]['name'])->toBe('legacy-open')
        ->and(app(DurableJoinRelease::class)->releaseIfReady(app(DurableRunStore::class)->find($runId)))->toBeFalse();
});
