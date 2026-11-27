<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\JoinOpenWait\JoinOpenWaitWorker;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\LockBarrier;
use BuiltByBerry\LaravelSwarm\Tests\Support\StrandedJoinShapeBreakers;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Rollout of the open-wait join rule (#615) on the real engines: swarm:recover puts a
 * queued-hierarchical run a pre-upgrade wait closer stranded `pending` at its parallel
 * boundary back at it, an ordinary advance job delivered for such a run is ignored, and
 * a parent whose child outcome was delivered no longer takes a place in the
 * terminal-children sweep.
 *
 * The scenario tests run each path end to end on MySQL and Postgres. The race tests run
 * two workers through the process concurrency driver and use LockBarrier to observe,
 * from the engine's lock-wait view, that one is blocked on the other's lock before it
 * goes on. They exercise the repair's own transaction: the durable row is locked first
 * and re-checked under the lock, so a writer that holds the run row either makes the
 * repair reject (it got there first) or waits for it and then sees the restored row.
 *
 * Every worker closure is built by a free function, so it serializes cleanly to the
 * child process, which runs it against bare testbench (JoinOpenWaitWorker::boot()).
 */
// Tagged `skip-locked-real-db` so the CI lane (`test:process-concurrency:ci`)
// excludes it under `--fail-on-skipped`: it requires a shared MySQL/Postgres
// connection and skips on the default in-memory SQLite.
pest()->group('process-concurrency', 'skip-locked-real-db');

function rolloutDbBarrierDir(): string
{
    $dir = sys_get_temp_dir().'/laravel-swarm-join-wait-rollout-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    rolloutDbBarrierDirs($dir);

    return $dir;
}

/**
 * @return array<int, string>
 */
function rolloutDbBarrierDirs(?string $add = null, bool $clear = false): array
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

function rolloutDbPlan(): array
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

function rolloutDbTwoGroupPlan(): array
{
    return [
        'start_at' => 'group_a',
        'nodes' => [
            'group_a' => ['type' => 'parallel', 'branches' => ['writer_a', 'editor_a'], 'next' => 'researcher_node'],
            'writer_a' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-a'],
            'editor_a' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor-a'],
            'researcher_node' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'between', 'next' => 'group_b'],
            'group_b' => ['type' => 'parallel', 'branches' => ['writer_b', 'editor_b'], 'next' => 'finish_node'],
            'writer_b' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-b'],
            'editor_b' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor-b'],
            'finish_node' => ['type' => 'finish', 'output_from' => 'editor_b'],
        ],
    ];
}

/**
 * Fake agent outputs that each take longer than a second, so a step never starts and
 * finishes within one second (the timing every real-DB queued-hierarchical test keeps).
 *
 * @param  array<int, string>  $outputs
 */
function rolloutDbSlow(array $outputs): Closure
{
    return function () use (&$outputs): string {
        usleep(1_100_000);

        return (string) array_shift($outputs);
    };
}

function rolloutDbFakes(array $plan): void
{
    FakeHierarchicalCoordinator::fake(function () use ($plan): array {
        usleep(1_100_000);

        return $plan;
    });
    FakeResearcher::fake(rolloutDbSlow(['between-out', 'child-research']));
    FakeWriter::fake(rolloutDbSlow(['writer-a-out', 'writer-b-out', 'child-writer']));
    FakeEditor::fake(rolloutDbSlow(['editor-a-out', 'editor-b-out', 'child-editor']));
}

function rolloutDbDurable(string $runId): array
{
    return (array) DB::table('swarm_durable_runs')->where('run_id', $runId)->first();
}

function rolloutDbHistory(string $runId): array
{
    return (array) DB::table('swarm_run_histories')->where('run_id', $runId)->first();
}

function rolloutDbSnapshot(string $runId): array
{
    return [
        'durable' => rolloutDbDurable($runId),
        'history' => rolloutDbHistory($runId),
        'outbox' => DB::table('swarm_durable_outbox')->where('run_id', $runId)->orderBy('id')->pluck('dispatch_type')->all(),
    ];
}

function rolloutDbCompleteBranches(string $runId, string $nodeId): void
{
    foreach (app(DurableRunStore::class)->branchesFor($runId, $nodeId) as $branch) {
        (new AdvanceDurableBranch($runId, (string) $branch['branch_id']))->handle(app(DurableSwarmManager::class));
    }
}

/**
 * A queued-hierarchical run at its first boundary whose signal wait a pre-upgrade
 * closer closed while the branches ran: the current closer writes every outcome, then
 * the old closer's own differences (durable and history `pending`) are applied, then
 * what $polls stray ordinary-job deliveries on the old code left behind, aged past the
 * grace period. Its step chain is lost.
 */
function rolloutDbStranded(string $runId, int $polls = 0): void
{
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, RunContext::from('queued-hierarchical-task', $runId)->toQueuePayload()))->handle(app(SwarmRunner::class));
    $manager = app(DurableSwarmManager::class);
    $manager->wait($runId, 'approval');
    expect($manager->signal($runId, 'approval')->accepted)->toBeTrue()
        ->and(rolloutDbDurable($runId)['status'])->toBe('waiting');

    $next = (int) rolloutDbDurable($runId)['next_step_index'];
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(array_merge([
        'status' => 'pending',
        'wait_reason' => null,
        'waiting_since' => null,
        'wait_timeout_at' => null,
    ], $polls > 0 ? ['next_step_index' => $next + $polls, 'current_step_index' => $next + $polls - 1] : []));
    DB::table('swarm_run_histories')->where('run_id', $runId)->update(array_merge(
        ['status' => 'pending'],
        $polls > 0 ? ['leased_until' => now('UTC')->addMinutes(5)] : [],
    ));
}

function rolloutDbAge(string $runId): void
{
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['updated_at' => now('UTC')->subMinutes(10)]);
}

function rolloutDbResume(string $runId): void
{
    (new ResumeQueuedHierarchicalSwarm($runId))->handle(app(QueuedHierarchicalCoordinator::class));
}

/**
 * Runs the repair's own transaction for one run. With $hold, once it holds the durable
 * row (inside the history step, before anything is written) it waits until the other
 * worker is observed blocked on it.
 */
function rolloutDbRepairer(string $runId, string $dir, bool $hold): Closure
{
    return static function () use ($runId, $dir, $hold): array {
        JoinOpenWaitWorker::boot();
        $me = LockBarrier::connectionId();
        $contended = null;

        if (! $hold) {
            LockBarrier::await($dir, 'writer-holds-run');
            LockBarrier::signal($dir, 'repairer-id', (string) $me);
        }

        try {
            $restored = app(DatabaseDurableRunStore::class)->restoreStrandedQueuedHierarchicalJoin($runId, 300, static function () use ($runId, $dir, $hold, $me, &$contended): bool {
                if ($hold) {
                    LockBarrier::signal($dir, 'repairer-holds-run');
                    $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'writer-id'), $me);
                }

                return app(DatabaseRunHistoryStore::class)->restoreQueuedHierarchicalBoundaryWait($runId);
            });
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => $restored ? 'restored' : 'rejected', 'contended' => $contended];
    };
}

/**
 * Opens a wait on the run. With $hold, once its createWait() holds the run row it
 * waits until the repairer is observed blocked on it; otherwise it starts once the
 * repairer holds the row and reports its connection for the repairer to watch.
 */
function rolloutDbWaitCreator(string $runId, string $dir, bool $hold): Closure
{
    return static function () use ($runId, $dir, $hold): array {
        $store = JoinOpenWaitWorker::bootHooked();
        $me = LockBarrier::connectionId();
        $contended = null;

        if ($hold) {
            $store->afterRunLock = static function () use ($store, $dir, $me, &$contended): void {
                $store->afterRunLock = null;
                LockBarrier::signal($dir, 'writer-holds-run');
                $contended = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'repairer-id'), $me);
            };
        } else {
            LockBarrier::await($dir, 'repairer-holds-run');
            LockBarrier::signal($dir, 'writer-id', (string) $me);
        }

        try {
            app(DurableSwarmManager::class)->wait($runId, 'new-obligation');
        } catch (SwarmException $e) {
            return ['outcome' => 'rejected', 'contended' => $contended];
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => 'created', 'contended' => $contended];
    };
}

/**
 * An already-entered pre-upgrade execution: an old worker's ordinary advance takes the
 * durable lease (its UPDATE holds the run row) and, before committing, waits until the
 * repairer is observed blocked on that row.
 */
function rolloutDbOldLeaseTaker(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        JoinOpenWaitWorker::boot();
        $me = LockBarrier::connectionId();

        try {
            $contended = DB::connection()->transaction(static function () use ($runId, $dir, $me): bool {
                $index = (int) DB::table('swarm_durable_runs')->where('run_id', $runId)->lockForUpdate()->value('next_step_index');
                $token = app(DatabaseDurableRunStore::class)->acquireLease($runId, $index, 300);

                if ($token === null) {
                    throw new RuntimeException('the old execution could not take the lease');
                }

                LockBarrier::signal($dir, 'writer-holds-run');

                return LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'repairer-id'), $me);
            });
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => 'leased', 'contended' => $contended];
    };
}

beforeEach(function (): void {
    if (in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true)) {
        $this->markTestSkipped(
            'Durable join-wait rollout test requires a shared database engine reachable from '
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

    rolloutDbFakes(rolloutDbPlan());
});

afterEach(function (): void {
    foreach (rolloutDbBarrierDirs(clear: true) as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

test('a stranded run, polled and its chain lost, is repaired by swarm:recover and completes through its own continuation', function (): void {
    $runId = 'rollout-db-'.bin2hex(random_bytes(4));
    rolloutDbStranded($runId, polls: 3);
    rolloutDbCompleteBranches($runId, 'parallel_node');
    rolloutDbAge($runId);

    expect(collect(app(DatabaseDurableRunStore::class)->strandedQueuedHierarchicalJoins(runId: $runId))->pluck('run_id')->all())->toBe([$runId]);

    Artisan::call('swarm:recover');

    expect(rolloutDbDurable($runId)['status'])->toBe('pending')
        ->and(rolloutDbDurable($runId)['current_step_index'])->toBeNull()
        ->and(rolloutDbHistory($runId)['status'])->toBe('waiting')
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'queued_resume')->count())->toBe(1);

    rolloutDbResume($runId);

    expect(rolloutDbHistory($runId)['status'])->toBe('completed')
        ->and(rolloutDbHistory($runId)['output'])->toBe('editor-a-out');
});

test('on the real engine the selector takes the stranded shape, with or without a dead lease, and nothing that breaks one condition of it', function (): void {
    $store = app(DatabaseDurableRunStore::class);
    $stranded = 'rollout-db-shape-'.bin2hex(random_bytes(4));
    rolloutDbStranded($stranded);
    rolloutDbAge($stranded);
    StrandedJoinShapeBreakers::expireDeadLease($stranded);
    $broken = [];

    foreach (StrandedJoinShapeBreakers::all() as $label => $break) {
        rolloutDbFakes(rolloutDbPlan());
        $runId = 'rollout-db-broken-'.bin2hex(random_bytes(4));
        rolloutDbStranded($runId);
        rolloutDbAge($runId);
        $break($runId);
        $broken[$label] = $runId;
    }

    // Scoped per run: the shared database keeps other tests' rows.
    expect(collect($store->strandedQueuedHierarchicalJoins(runId: $stranded))->pluck('run_id')->all())->toBe([$stranded]);

    foreach ($broken as $label => $runId) {
        expect($store->strandedQueuedHierarchicalJoins(runId: $runId))->toBe([], $label);
    }

    // The restore re-checks every condition under its lock and writes nothing.
    foreach ($broken as $label => $runId) {
        $before = rolloutDbSnapshot($runId);
        $restored = $store->restoreStrandedQueuedHierarchicalJoin($runId, 300, fn (): bool => app(DatabaseRunHistoryStore::class)->restoreQueuedHierarchicalBoundaryWait($runId));
        expect($restored)->toBeFalse($label)
            ->and(rolloutDbSnapshot($runId))->toBe($before, $label);
    }
});

test('on the real engine swarm:recover leaves healthy queued-hierarchical runs as they are', function (): void {
    $released = 'rollout-db-released-'.bin2hex(random_bytes(4));
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, RunContext::from('queued-hierarchical-task', $released)->toQueuePayload()))->handle(app(SwarmRunner::class));
    rolloutDbCompleteBranches($released, 'parallel_node');
    expect(rolloutDbDurable($released)['status'])->toBe('pending')
        ->and(rolloutDbHistory($released)['status'])->toBe('waiting');

    rolloutDbFakes(rolloutDbPlan());
    $continuing = 'rollout-db-continuing-'.bin2hex(random_bytes(4));
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, RunContext::from('queued-hierarchical-task', $continuing)->toQueuePayload()))->handle(app(SwarmRunner::class));
    rolloutDbCompleteBranches($continuing, 'parallel_node');
    DB::table('swarm_run_histories')->where('run_id', $continuing)->update(['status' => 'running', 'execution_token' => 'continuation', 'leased_until' => now('UTC')->addMinutes(5)]);

    foreach ([$released, $continuing] as $runId) {
        rolloutDbAge($runId);
    }

    $before = [$released => rolloutDbSnapshot($released), $continuing => rolloutDbSnapshot($continuing)];

    foreach ($before as $runId => $snapshot) {
        expect(app(DatabaseDurableRunStore::class)->strandedQueuedHierarchicalJoins(runId: $runId))->toBe([]);
        Artisan::call('swarm:recover', ['--run-id' => $runId]);

        expect(rolloutDbDurable($runId)['status'])->toBe($snapshot['durable']['status'])
            ->and(rolloutDbHistory($runId))->toBe($snapshot['history']);
    }
});

test('a delayed stray advance job is ignored at the repaired join, at a later boundary that reuses its index, and under the continuation lease', function (): void {
    rolloutDbFakes(rolloutDbTwoGroupPlan());
    $runId = 'rollout-db-two-'.bin2hex(random_bytes(4));
    rolloutDbStranded($runId);
    rolloutDbCompleteBranches($runId, 'group_a');
    $secondBoundaryIndex = (int) rolloutDbDurable($runId)['next_step_index'] + 3;
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['next_step_index' => $secondBoundaryIndex, 'current_step_index' => $secondBoundaryIndex - 1]);
    rolloutDbAge($runId);
    $stray = fn () => (new AdvanceDurableSwarm($runId, $secondBoundaryIndex))->handle(app(DurableSwarmManager::class));

    Artisan::call('swarm:recover');
    $before = rolloutDbSnapshot($runId);
    $stray();
    expect(rolloutDbSnapshot($runId))->toBe($before);

    rolloutDbResume($runId);
    expect(rolloutDbDurable($runId)['current_node_id'])->toBe('group_b')
        ->and((int) rolloutDbDurable($runId)['next_step_index'])->toBe($secondBoundaryIndex);

    rolloutDbCompleteBranches($runId, 'group_b');
    expect(rolloutDbDurable($runId)['status'])->toBe('pending');
    $before = rolloutDbSnapshot($runId);
    $stray();
    expect(rolloutDbSnapshot($runId))->toBe($before);

    rolloutDbResume($runId);
    expect(rolloutDbHistory($runId)['status'])->toBe('completed')
        ->and(rolloutDbHistory($runId)['output'])->toBe('editor-b-out');
});

test('a parent whose child outcome was delivered does not take the only place in the terminal-children sweep', function (): void {
    $manager = app(DurableSwarmManager::class);
    $reconciled = 'rollout-db-reconciled-'.bin2hex(random_bytes(4));
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, RunContext::from('queued-hierarchical-task', $reconciled)->toQueuePayload()))->handle(app(SwarmRunner::class));
    $child = $manager->dispatchChildSwarm($reconciled, FakeSequentialSwarm::class, 'child-task')->childRunId;

    foreach ([0, 1, 2] as $step) {
        (new AdvanceDurableSwarm($child, $step))->handle($manager);
    }

    rolloutDbFakes(rolloutDbPlan());
    $owed = 'rollout-db-owed-'.bin2hex(random_bytes(4));
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, RunContext::from('queued-hierarchical-task', $owed)->toQueuePayload()))->handle(app(SwarmRunner::class));
    $owedChild = $manager->dispatchChildSwarm($owed, FakeSequentialSwarm::class, 'child-task')->childRunId;
    DB::table('swarm_durable_child_runs')->where('child_run_id', $owedChild)->update(['status' => 'completed', 'updated_at' => now('UTC')->addMinute()]);

    expect(collect(app(DatabaseDurableRunStore::class)->parentsWaitingOnTerminalChildren(limit: 1))->pluck('run_id')->all())->toBe([$owed]);
});

test('a wait created while holding the run row first makes the repair reject, without a deadlock abort', function (): void {
    $runId = 'rollout-db-wait-first-'.bin2hex(random_bytes(4));
    // Polled by an old worker: its stamped current step still lets a wait open (a run
    // with none reads as a released join and refuses it).
    rolloutDbStranded($runId, polls: 3);
    rolloutDbAge($runId);
    $dir = rolloutDbBarrierDir();

    [$creator, $repairer] = app(ConcurrencyManager::class)->driver('process')->run([
        rolloutDbWaitCreator($runId, $dir, hold: true),
        rolloutDbRepairer($runId, $dir, hold: false),
    ]);

    expect($creator)->toBe(['outcome' => 'created', 'contended' => true], json_encode($creator))
        ->and($repairer)->toBe(['outcome' => 'rejected', 'contended' => null], json_encode($repairer))
        // The wait itself put the run back at its boundary (durable and history
        // waiting), where closing it releases the join as on any healthy run.
        ->and(rolloutDbDurable($runId)['status'])->toBe('waiting')
        ->and(rolloutDbHistory($runId)['status'])->toBe('waiting')
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('status', 'waiting')->count())->toBe(1);
});

test('a wait created while the repair holds the run row waits for it and opens at the restored boundary', function (): void {
    $runId = 'rollout-db-repair-first-'.bin2hex(random_bytes(4));
    rolloutDbStranded($runId);
    rolloutDbAge($runId);
    $dir = rolloutDbBarrierDir();

    [$repairer, $creator] = app(ConcurrencyManager::class)->driver('process')->run([
        rolloutDbRepairer($runId, $dir, hold: true),
        rolloutDbWaitCreator($runId, $dir, hold: false),
    ]);

    expect($repairer)->toBe(['outcome' => 'restored', 'contended' => true], json_encode($repairer))
        ->and($creator)->toBe(['outcome' => 'created', 'contended' => null], json_encode($creator))
        ->and(rolloutDbDurable($runId)['status'])->toBe('waiting')
        ->and(rolloutDbHistory($runId)['status'])->toBe('waiting')
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('status', 'waiting')->count())->toBe(1);
});

test('an old execution that takes the durable lease first makes the repair reject once it commits', function (): void {
    $runId = 'rollout-db-old-lease-'.bin2hex(random_bytes(4));
    rolloutDbStranded($runId);
    rolloutDbAge($runId);
    $dir = rolloutDbBarrierDir();

    [$old, $repairer] = app(ConcurrencyManager::class)->driver('process')->run([
        rolloutDbOldLeaseTaker($runId, $dir),
        rolloutDbRepairer($runId, $dir, hold: false),
    ]);

    expect($old)->toBe(['outcome' => 'leased', 'contended' => true], json_encode($old))
        ->and($repairer)->toBe(['outcome' => 'rejected', 'contended' => null], json_encode($repairer))
        ->and(rolloutDbDurable($runId)['status'])->toBe('pending')
        ->and(rolloutDbDurable($runId)['execution_token'])->not->toBeNull()
        ->and(rolloutDbHistory($runId)['status'])->toBe('pending');
});
