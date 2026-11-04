<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\BranchWaitPayload;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\FanoutCloser\FanoutCloserWorker;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\LockBarrier;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * A wait closer (a signal, a wait timeout, a child's outcome) and a fan-out on the same
 * run take the run row first, so they cannot deadlock on the run, context and history
 * rows (#611, release finding FANOUT-CLOSER-CONTEXT-CYCLE); and a signal whose
 * acceptance aborts leaves nothing behind, so it can be sent again with the same
 * idempotency key.
 *
 * Two workers run through the process concurrency driver. LockBarrier reads "this worker
 * is blocked on that one's lock" from the engine's own lock-wait view, so each race proves
 * the contention it claims; where the fixed order means a worker no longer waits, the
 * handshake also accepts the other worker having finished.
 *
 * Every worker closure is built by a free function so it serializes cleanly to the child
 * process, which runs it against bare testbench: FanoutCloserWorker::bootHooked() repeats
 * the package configuration, and only autoloaded classes are referenced.
 */
// Tagged `skip-locked-real-db` so the CI lane (`test:process-concurrency:ci`) excludes it
// under `--fail-on-skipped`: it requires a shared MySQL/Postgres connection.
pest()->group('process-concurrency', 'skip-locked-real-db');

function fanoutCloserDir(): string
{
    $dir = sys_get_temp_dir().'/laravel-swarm-fanout-closer-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    fanoutCloserDirs($dir);

    return $dir;
}

/**
 * @return array<int, string>
 */
function fanoutCloserDirs(?string $add = null, bool $clear = false): array
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
 * finding MYSQL-LEASE); these tests are not about it.
 */
function fanoutCloserSlow(string $output): Closure
{
    return function () use ($output): string {
        usleep(1_100_000);

        return $output;
    };
}

/**
 * A run of a parallel swarm that holds a step lease and has an open application wait —
 * the state a step is in after calling wait() and before it fans out: createWait() keeps
 * the lease.
 *
 * @return array{0: string, 1: string}
 */
function fanoutCloserLeasedWaitingRun(?int $timeoutSeconds = null): array
{
    $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
    $token = app(DurableRunStore::class)->acquireLease($runId, 0, 300);
    expect($token)->not->toBeNull();
    app(DurableSwarmManager::class)->wait($runId, 'approval', timeoutSeconds: $timeoutSeconds);

    return [$runId, (string) $token];
}

/**
 * As fanoutCloserLeasedWaitingRun(), with the open wait a child swarm's: the parent holds
 * a step lease and a child:<id> wait, and the child has finished.
 *
 * @return array{0: string, 1: string, 2: string}
 */
function fanoutCloserLeasedRunWithFinishedChild(): array
{
    $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
    $token = app(DurableRunStore::class)->acquireLease($runId, 0, 300);
    expect($token)->not->toBeNull();
    $child = app(DurableSwarmManager::class)->dispatchChildSwarm($runId, FakeSequentialSwarm::class, 'child-task');
    expect(app(DurableRunStore::class)->updateChildRun($child->childRunId, 'completed', 'child-out'))->toBeTrue();

    return [$runId, (string) $token, $child->childRunId];
}

/**
 * @return array<int, array<string, mixed>>
 */
function fanoutCloserBranches(): array
{
    return [
        ['branch_id' => 'parallel:0', 'step_index' => 0, 'node_id' => null, 'agent_class' => FakeResearcher::class, 'parent_node_id' => 'parallel', 'input' => 'parallel-durable-task', 'metadata' => ['parallel_branch_index' => 0]],
        ['branch_id' => 'parallel:1', 'step_index' => 1, 'node_id' => null, 'agent_class' => FakeWriter::class, 'parent_node_id' => 'parallel', 'input' => 'parallel-durable-task', 'metadata' => ['parallel_branch_index' => 1]],
    ];
}

function fanoutCloserRunStatus(string $runId): ?string
{
    return DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status');
}

function fanoutCloserWaitStatus(string $runId, string $name): ?string
{
    return DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', $name)->value('status');
}

/**
 * @return array<string, mixed>
 */
function fanoutCloserContextMetadata(string $runId): array
{
    return app(ContextStore::class)->find($runId)['metadata'] ?? [];
}

/**
 * The fan-out, through the store: waitForBranches() for a leased run, paused once it has
 * written the context row (and, with the fix, locked the run row) until the closer is
 * observed blocked on one of its locks.
 *
 * @param  array<int, array<string, mixed>>  $branches
 */
function fanoutCloserHoldingFanOut(string $runId, string $token, array $branches, string $dir): Closure
{
    return static function () use ($runId, $token, $branches, $dir): array {
        $store = FanoutCloserWorker::bootHooked();
        $me = LockBarrier::connectionId();
        $contended = false;
        $waitingOn = null;
        $locks = null;
        $store->beforeBranchInsert = static function () use ($dir, $me, &$contended, &$waitingOn, &$locks): void {
            LockBarrier::signal($dir, 'fanout-holds', (string) $me);
            $closer = (int) LockBarrier::await($dir, 'closer-started');
            $contended = LockBarrier::awaitBlockedBy($closer, $me);
            $waitingOn = $contended ? FanoutCloserWorker::waitingOn($closer) : null;
            $locks = $contended ? null : LockBarrier::lockWaitSnapshot();
        };

        try {
            $context = RunContext::fromPayload((array) app(ContextStore::class)->find($runId));
            $store->waitForBranches($runId, new BranchWaitPayload(
                executionToken: $token,
                nextStepIndex: 2,
                parentNodeId: 'parallel',
                context: $context,
                ttlSeconds: 3600,
                branches: $branches,
            ));
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => 'committed', 'contended' => $contended, 'waiting_on' => $waitingOn, 'locks' => $locks];
    };
}

/**
 * @param  Closure(): mixed  $close
 */
function fanoutCloserCloser(string $dir, string $after, Closure $close): Closure
{
    return static function () use ($dir, $after, $close): array {
        FanoutCloserWorker::bootHooked();
        LockBarrier::await($dir, $after);
        // Marked as the close starts, not at the run lock: where a closer first waits on
        // the fan-out depends on the engine. On MySQL 8 its wait-row UPDATE already waits
        // for the run row (the foreign-key check on the index it rewrites); on MySQL 9 and
        // Postgres it first waits at its own run lock. Either way the fan-out must then
        // observe it blocked, on the runs table.
        LockBarrier::signal($dir, 'closer-started', (string) LockBarrier::connectionId());

        try {
            $result = $close();
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        } finally {
            LockBarrier::signal($dir, 'closer-done');
        }

        return ['outcome' => 'returned', 'result' => $result];
    };
}

function fanoutCloserSignalCloser(string $runId, string $dir, string $after): Closure
{
    return fanoutCloserCloser($dir, $after, static function () use ($runId): bool {
        return app(DurableSwarmManager::class)->signal($runId, 'approval', ['approved' => true])->accepted;
    });
}

function fanoutCloserRecoverCloser(string $runId, string $dir, string $after): Closure
{
    return fanoutCloserCloser($dir, $after, static function () use ($runId): bool {
        // The sweep isolates each timed-out wait and logs its failure, so its return
        // proves nothing on its own: the test asserts the closed wait and its outcome.
        app(DurableSwarmManager::class)->recover($runId);

        return true;
    });
}

afterEach(function (): void {
    foreach (fanoutCloserDirs(clear: true) as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

beforeEach(function (): void {
    if (in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true)) {
        $this->markTestSkipped(
            'The fan-out/closer lock-order test requires a shared database engine reachable from '
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

    FakeResearcher::fake(fanoutCloserSlow('research-out'));
    FakeWriter::fake(fanoutCloserSlow('writer-out'));
    FakeEditor::fake(fanoutCloserSlow('editor-out'));
});

/**
 * The run row is the first row the fan-out takes, so a closer arriving while it is in
 * flight waits on the run row and then reads the fan-out committed.
 */
function fanoutCloserAssertWaitedOnRun(array $fanOut): void
{
    expect($fanOut['outcome'])->toBe('committed', json_encode($fanOut))
        ->and($fanOut['contended'])->toBeTrue(json_encode($fanOut))
        ->and($fanOut['waiting_on'])->toContain('swarm_durable_runs');
}

test('a signal closing the last wait while the run fans out waits for the fan-out: no deadlock, and the run waits on its branches', function (): void {
    [$runId, $token] = fanoutCloserLeasedWaitingRun();
    $dir = fanoutCloserDir();

    [$fanOut, $closer] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        fanoutCloserHoldingFanOut($runId, $token, fanoutCloserBranches(), $dir),
        fanoutCloserSignalCloser($runId, $dir, 'fanout-holds'),
    ]);

    fanoutCloserAssertWaitedOnRun($fanOut);
    expect($closer)->toBe(['outcome' => 'returned', 'result' => true], json_encode($closer))
        ->and(fanoutCloserWaitStatus($runId, 'approval'))->toBe('signalled')
        ->and(fanoutCloserRunStatus($runId))->toBe('waiting')
        ->and(DB::table('swarm_durable_branches')->where('run_id', $runId)->count())->toBe(2)
        ->and(fanoutCloserContextMetadata($runId)['durable_wait_outcomes']['approval']['status'] ?? null)->toBe('signalled');
});

test('a wait timing out while the run fans out waits for the fan-out: no deadlock, and the outcome is published', function (): void {
    [$runId, $token] = fanoutCloserLeasedWaitingRun(timeoutSeconds: 60);
    $past = now('UTC')->subMinute();
    DB::table('swarm_durable_waits')->where('run_id', $runId)->update(['timeout_at' => $past]);
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['wait_timeout_at' => $past]);
    $dir = fanoutCloserDir();

    [$fanOut, $closer] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        fanoutCloserHoldingFanOut($runId, $token, fanoutCloserBranches(), $dir),
        fanoutCloserRecoverCloser($runId, $dir, 'fanout-holds'),
    ]);

    fanoutCloserAssertWaitedOnRun($fanOut);
    expect($closer)->toBe(['outcome' => 'returned', 'result' => true], json_encode($closer))
        ->and(fanoutCloserWaitStatus($runId, 'approval'))->toBe('timed_out')
        ->and(fanoutCloserRunStatus($runId))->toBe('waiting')
        ->and(DB::table('swarm_durable_branches')->where('run_id', $runId)->count())->toBe(2)
        ->and(fanoutCloserContextMetadata($runId)['durable_wait_outcomes']['approval']['status'] ?? null)->toBe('timed_out');
});

test('a child outcome closing the last wait while the run fans out waits for the fan-out: no deadlock, and the child is published', function (): void {
    [$runId, $token, $childRunId] = fanoutCloserLeasedRunWithFinishedChild();
    $dir = fanoutCloserDir();

    [$fanOut, $closer] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        fanoutCloserHoldingFanOut($runId, $token, fanoutCloserBranches(), $dir),
        fanoutCloserRecoverCloser($runId, $dir, 'fanout-holds'),
    ]);

    fanoutCloserAssertWaitedOnRun($fanOut);
    expect($closer)->toBe(['outcome' => 'returned', 'result' => true], json_encode($closer))
        ->and(fanoutCloserWaitStatus($runId, 'child:'.$childRunId))->toBe('child_completed')
        ->and(fanoutCloserRunStatus($runId))->toBe('waiting')
        ->and(DB::table('swarm_durable_branches')->where('run_id', $runId)->count())->toBe(2)
        ->and(fanoutCloserContextMetadata($runId)['durable_child_runs'][$childRunId]['status'] ?? null)->toBe('completed');
});

/**
 * The real step job of a fan-out caller, with an application wait opened while the step
 * holds its lease (what a step calling wait() does), run through a store that pauses at the
 * entry of waitForBranches() — after anything the caller wrote first in its transaction —
 * until the closer has either finished or is observed blocked on this worker.
 */
function fanoutCloserCallerFanOut(string $runId, int $step, string $dir): Closure
{
    return static function () use ($runId, $step, $dir): array {
        $store = FanoutCloserWorker::bootHooked();
        FakeResearcher::fake(static fn (): string => 'research-out');
        FakeWriter::fake(static fn (): string => 'writer-out');
        FakeEditor::fake(static fn (): string => 'editor-out');
        $me = LockBarrier::connectionId();
        $closerBlocked = null;
        // The application opens a wait while the step holds its lease, once the step has
        // marked itself running and before its fan-out transaction begins: from a
        // SwarmStarted listener at step 0 (the top-level parallel fan-out), and from the
        // step's pre-checkpoint seam at a hierarchical parallel node.
        $manager = app(DurableSwarmManager::class);
        $open = static function () use ($manager, $runId): void {
            $manager->wait($runId, 'approval');
        };

        if ($step === 0) {
            Event::listen(SwarmStarted::class, $open);
        } else {
            $manager->beforeStepCheckpointForTesting($open);
        }
        $store->beforeWaitForBranches = static function () use ($dir, $me, &$closerBlocked): void {
            LockBarrier::signal($dir, 'fanout-at-entry', (string) $me);
            $closer = (int) LockBarrier::await($dir, 'closer-started');
            $deadline = microtime(true) + 15.0;

            while (microtime(true) < $deadline) {
                if (is_file($dir.'/closer-done')) {
                    $closerBlocked = false;

                    return;
                }

                if (LockBarrier::isBlockedBy($closer, $me)) {
                    $closerBlocked = true;

                    return;
                }

                usleep(10_000);
            }
        };

        try {
            (new AdvanceDurableSwarm($runId, $step))->handle($manager);
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => 'committed', 'closer_blocked' => $closerBlocked];
    };
}

test('top-level parallel: a signal closing the wait while the step fans out does not deadlock on the history row', function (): void {
    $runId = FakeParallelSwarm::make()->dispatchDurable('parallel-durable-task')->runId;
    $dir = fanoutCloserDir();

    [$fanOut, $closer] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        fanoutCloserCallerFanOut($runId, 0, $dir),
        fanoutCloserSignalCloser($runId, $dir, 'fanout-at-entry'),
    ]);

    // The fan-out writes nothing the closer needs before waitForBranches() takes the run
    // row, so the closer finishes first. The closer's context outcome is not asserted:
    // the step's in-memory context overwrites it (release finding
    // FCC-STALE-CONTEXT-OVERWRITE).
    expect($fanOut)->toBe(['outcome' => 'committed', 'closer_blocked' => false], json_encode($fanOut))
        ->and($closer)->toBe(['outcome' => 'returned', 'result' => true], json_encode($closer))
        ->and(fanoutCloserWaitStatus($runId, 'approval'))->toBe('signalled')
        ->and(fanoutCloserRunStatus($runId))->toBe('waiting')
        ->and(DB::table('swarm_durable_branches')->where('run_id', $runId)->count())->toBe(3);
});

test('step-durable hierarchical: a signal closing the wait while the coordinator step fans out does not deadlock on the history row', function (): void {
    // Step 0, the coordinator, plans a parallel node; step 1 fans out to it.
    FakeHierarchicalCoordinator::fake(function (): array {
        usleep(1_100_000);

        return [
            'start_at' => 'parallel_node',
            'nodes' => [
                'parallel_node' => ['type' => 'parallel', 'branches' => ['writer_node', 'editor_node'], 'next' => 'finish_node'],
                'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
                'editor_node' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor-branch'],
                'finish_node' => ['type' => 'finish', 'output_from' => 'editor_node'],
            ],
        ];
    });
    $runId = FakeHierarchicalFullSwarm::make()->dispatchDurable('hierarchical-durable-task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    expect(app(DurableSwarmManager::class)->find($runId)['route_cursor']['current_node_id'] ?? null)->toBe('parallel_node');
    $dir = fanoutCloserDir();

    [$fanOut, $closer] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        fanoutCloserCallerFanOut($runId, 1, $dir),
        fanoutCloserSignalCloser($runId, $dir, 'fanout-at-entry'),
    ]);

    expect($fanOut)->toBe(['outcome' => 'committed', 'closer_blocked' => false], json_encode($fanOut))
        ->and($closer)->toBe(['outcome' => 'returned', 'result' => true], json_encode($closer))
        ->and(fanoutCloserWaitStatus($runId, 'approval'))->toBe('signalled')
        ->and(fanoutCloserRunStatus($runId))->toBe('waiting')
        ->and(DB::table('swarm_durable_branches')->where('run_id', $runId)->count())->toBe(2);
});
