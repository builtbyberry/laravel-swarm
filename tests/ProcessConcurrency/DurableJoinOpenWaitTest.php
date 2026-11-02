<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
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
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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
 * Fake agent outputs that each take longer than a second: on MySQL a step that starts
 * and finishes within one second trips the pre-existing history-lease bug (release
 * finding MYSQL-LEASE); this test is not about it.
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
 * The coordinator's route plan, answered after more than a second (MYSQL-LEASE, as
 * above: an instant coordinator step leaves the queued run's history `running`).
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
    $logger = Mockery::mock(Psr\Log\LoggerInterface::class)->shouldIgnoreMissing();
    $logger->shouldReceive('error')->andReturnUsing(function (string|Stringable $message) use ($runId): void {
        if (str_contains((string) $message, 'branch failed')) {
            app(DurableSwarmManager::class)->signal($runId, 'approval');
        }
    });
    app()->instance(Psr\Log\LoggerInterface::class, $logger);
    app()->forgetInstance(DurableSwarmManager::class);

    (new InvokeSwarm(FakeHierarchicalParallelFailBranchSwarm::class, RunContext::from('queued-hierarchical-task', $runId)->toQueuePayload()))->handle(app(SwarmRunner::class));
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'approval');
    $branches = collect(app(DurableRunStore::class)->branchesFor($runId, 'parallel_node'));

    (new AdvanceDurableBranch($runId, (string) $branches->firstWhere('agent_class', FakeWriter::class)['branch_id']))->handle($manager);
    (new AdvanceDurableBranch($runId, (string) $branches->firstWhere('agent_class', FailingPromptAgent::class)['branch_id']))->handle($manager);

    expect(joinOpenWaitDbRunStatus($runId))->toBe('failed')
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
