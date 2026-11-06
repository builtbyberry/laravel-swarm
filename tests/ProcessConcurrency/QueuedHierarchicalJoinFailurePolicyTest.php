<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJoinRelease;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FailingPromptAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalParallelFailBranchSwarm;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\JoinOpenWait\JoinOpenWaitWorker;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\LockBarrier;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * A queued-hierarchical join applies the durable parallel failure policy as the
 * step-durable join does (#612), on the real engines.
 *
 * The scenario tests drive each policy end to end on MySQL and Postgres. The race test
 * runs two resume deliveries for one failing join in child processes while a third
 * holds the join release, and uses LockBarrier to observe, from the engine's lock-wait
 * view, that both are blocked on its run row before letting it commit — no timed holds.
 *
 * Every worker closure is built by a free function, so it serializes cleanly to the
 * child process, which runs it against bare testbench: JoinOpenWaitWorker::boot()
 * repeats the package configuration. The failure policy is read from the run's own
 * context, so the children need no policy configuration.
 */
// Tagged `skip-locked-real-db` so the CI lane (`test:process-concurrency:ci`)
// excludes it under `--fail-on-skipped`: it requires a shared MySQL/Postgres
// connection and skips on the default in-memory SQLite.
pest()->group('process-concurrency', 'skip-locked-real-db');

/**
 * @return array<int, string>
 */
function qhfpDbBarrierDirs(?string $add = null, bool $clear = false): array
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

function qhfpDbBarrierDir(): string
{
    $dir = sys_get_temp_dir().'/laravel-swarm-qh-join-failure-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    qhfpDbBarrierDirs($dir);

    return $dir;
}

/**
 * @return array<string, mixed>
 */
function qhfpDbPlan(bool $literalFinish = false): array
{
    return [
        'start_at' => 'parallel_node',
        'nodes' => [
            'parallel_node' => ['type' => 'parallel', 'branches' => ['writer_node', 'failing_node'], 'next' => 'finish_node'],
            'writer_node' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer-branch'],
            'failing_node' => ['type' => 'worker', 'agent' => FailingPromptAgent::class, 'prompt' => 'failing-branch'],
            'finish_node' => $literalFinish
                ? ['type' => 'finish', 'output' => 'literal-final']
                : ['type' => 'finish', 'output_from' => 'writer_node'],
        ],
    ];
}

/** Start a queued multi_worker run under the given policy; it waits at its join. */
function qhfpDbStart(string $prefix, string $policy, bool $literalFinish = false): string
{
    config()->set('swarm.durable.parallel.failure_policy', $policy);
    FakeHierarchicalCoordinator::fake([qhfpDbPlan($literalFinish)]);
    $runId = $prefix.bin2hex(random_bytes(4));
    (new InvokeSwarm(FakeHierarchicalParallelFailBranchSwarm::class, RunContext::from('queued-hierarchical-task', $runId)->toQueuePayload()))->handle(app(SwarmRunner::class));

    return $runId;
}

function qhfpDbAdvanceBranch(string $runId, string $agentClass): void
{
    $branch = DB::table('swarm_durable_branches')->where('run_id', $runId)->where('agent_class', $agentClass)->first();
    expect($branch)->not->toBeNull();

    (new AdvanceDurableBranch($runId, (string) $branch->branch_id))->handle(app(DurableSwarmManager::class));
}

function qhfpDbResume(string $runId): void
{
    (new ResumeQueuedHierarchicalSwarm($runId))->handle(app(QueuedHierarchicalCoordinator::class));
}

function qhfpDbExpectedMessage(string $runId): string
{
    $failed = array_values(array_filter(
        app(DurableRunStore::class)->branchesFor($runId, 'parallel_node'),
        static fn (array $branch): bool => $branch['status'] === 'failed',
    ));

    return 'Durable parallel branches failed: '.implode(', ', array_map(static fn (array $branch): string => (string) $branch['branch_id'], $failed));
}

/**
 * @return array<string, mixed>
 */
function qhfpDbRecord(string $runId): array
{
    $history = app(RunHistoryStore::class)->find($runId);
    $durable = app(DurableSwarmManager::class)->find($runId);
    $context = RunContext::fromPayload((array) app(ContextStore::class)->find($runId));

    return [
        'history_status' => $history['status'],
        'history_error' => $history['error']['message'] ?? null,
        'durable_status' => $durable['status'],
        'durable_failure' => $durable['failure']['message'] ?? null,
        'branches' => array_column((array) ($context->metadata['durable_parallel_branches'] ?? []), 'status', 'node_id'),
    ];
}

/**
 * Releases the run to its join and holds the release — run row written, outbox row
 * not yet — until both resume workers are observed blocked on it.
 */
function qhfpDbHoldingRelease(string $runId, string $dir): Closure
{
    return static function () use ($runId, $dir): array {
        $store = JoinOpenWaitWorker::bootHooked();
        $me = LockBarrier::connectionId();
        $contended = false;
        $locks = null;
        $store->afterJoinCheck = static function (string $runId, bool $released) use ($dir, $me, &$contended, &$locks): void {
            LockBarrier::signal($dir, 'release-holds-run');
            $a = (int) LockBarrier::await($dir, 'resume-a-id');
            $b = (int) LockBarrier::await($dir, 'resume-b-id');
            // Postgres reports a waiter queued behind another waiter for the same row
            // lock as blocked by that waiter, not by the holder; so on Postgres a resumer
            // queued behind the other counts, as long as one of them waits on this holder.
            $queued = DB::connection()->getDriverName() === 'pgsql';
            $deadline = microtime(true) + 15.0;

            while (! $contended && microtime(true) < $deadline) {
                $aOnMe = LockBarrier::isBlockedBy($a, $me);
                $bOnMe = LockBarrier::isBlockedBy($b, $me);
                $contended = ($aOnMe || $bOnMe)
                    && ($aOnMe || ($queued && LockBarrier::isBlockedBy($a, $b)))
                    && ($bOnMe || ($queued && LockBarrier::isBlockedBy($b, $a)));

                if (! $contended) {
                    usleep(10_000);
                }
            }

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
 * Delivers a resume once the release holds the run row. Reports whether this delivery
 * returned a completed response (it never should: the join must fail).
 */
function qhfpDbResumer(string $runId, string $dir, string $name): Closure
{
    return static function () use ($runId, $dir, $name): array {
        JoinOpenWaitWorker::boot();
        LockBarrier::signal($dir, $name.'-id', (string) LockBarrier::connectionId());
        LockBarrier::await($dir, 'release-holds-run');

        try {
            $response = app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId);
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => $response !== null ? 'completed' : 'returned'];
    };
}

beforeEach(function (): void {
    if (in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true)) {
        $this->markTestSkipped(
            'Queued-hierarchical join failure-policy test requires a shared database engine reachable from '
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

    FakeWriter::fake(['writer-out']);
});

afterEach(function (): void {
    foreach (qhfpDbBarrierDirs(clear: true) as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

test('queued-hierarchical partial_success with every branch failed fails the run on the real engine (#612)', function (): void {
    FakeWriter::fake(function (): string {
        throw new RuntimeException('Writer branch failed.');
    });
    $runId = qhfpDbStart('qhfp-db-partial-', 'partial_success', literalFinish: true);
    qhfpDbAdvanceBranch($runId, FailingPromptAgent::class);

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('waiting');

    qhfpDbAdvanceBranch($runId, FakeWriter::class);
    qhfpDbResume($runId);

    $message = qhfpDbExpectedMessage($runId);

    expect(qhfpDbRecord($runId))->toBe([
        'history_status' => 'failed',
        'history_error' => $message,
        'durable_status' => 'failed',
        'durable_failure' => $message,
        'branches' => ['writer_node' => 'failed', 'failing_node' => 'failed'],
    ]);
});

test('queued-hierarchical collect_failures fails the run once every branch is terminal on the real engine (#612)', function (): void {
    $runId = qhfpDbStart('qhfp-db-collect-', 'collect_failures');
    qhfpDbAdvanceBranch($runId, FailingPromptAgent::class);

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('waiting')
        ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting');

    qhfpDbAdvanceBranch($runId, FakeWriter::class);
    qhfpDbResume($runId);

    $message = qhfpDbExpectedMessage($runId);

    expect(qhfpDbRecord($runId))->toBe([
        'history_status' => 'failed',
        'history_error' => $message,
        'durable_status' => 'failed',
        'durable_failure' => $message,
        'branches' => ['writer_node' => 'completed', 'failing_node' => 'failed'],
    ]);
});

test('queued-hierarchical fail_run still fails from the branch worker on the real engine, and a later resume changes nothing (#612)', function (): void {
    $runId = qhfpDbStart('qhfp-db-fail-run-', 'fail_run');
    qhfpDbAdvanceBranch($runId, FailingPromptAgent::class);

    $record = qhfpDbRecord($runId);
    expect($record['history_status'])->toBe('failed')
        ->and($record['durable_status'])->toBe('failed')
        ->and($record['history_error'])->toBe(qhfpDbExpectedMessage($runId))
        ->and($record['branches'])->toBe(['writer_node' => 'pending', 'failing_node' => 'failed']);

    qhfpDbResume($runId);

    expect(qhfpDbRecord($runId))->toBe($record);
});

test('a failing queued-hierarchical join whose durable lease is held runs nothing; recovery then fails it on the real engine (#612)', function (): void {
    $runId = qhfpDbStart('qhfp-db-lease-', 'collect_failures');
    qhfpDbAdvanceBranch($runId, FailingPromptAgent::class);
    qhfpDbAdvanceBranch($runId, FakeWriter::class);

    DB::table('swarm_durable_runs')->where('run_id', $runId)->update([
        'execution_token' => 'competing-holder',
        'leased_until' => now('UTC')->addMinutes(5),
    ]);

    expect(app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId))->toBeNull();

    $history = DB::table('swarm_run_histories')->where('run_id', $runId)->first();
    expect($history->status)->toBe('running')
        ->and($history->execution_token)->not->toBeNull()
        ->and(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('pending');

    DB::table('swarm_run_histories')->where('run_id', $runId)->update(['leased_until' => now('UTC')->subMinutes(10)]);
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['leased_until' => now('UTC')->subMinutes(10)]);

    Bus::fake([ResumeQueuedHierarchicalSwarm::class]);
    Artisan::call('swarm:recover');
    Bus::assertDispatched(ResumeQueuedHierarchicalSwarm::class, fn (ResumeQueuedHierarchicalSwarm $job): bool => $job->runId === $runId);

    qhfpDbResume($runId);

    $message = qhfpDbExpectedMessage($runId);
    expect(qhfpDbRecord($runId))->toBe([
        'history_status' => 'failed',
        'history_error' => $message,
        'durable_status' => 'failed',
        'durable_failure' => $message,
        'branches' => ['writer_node' => 'completed', 'failing_node' => 'failed'],
    ]);
});

test('two resumes delivered while a failing join is being released both wait for it; the run is failed once and never continued (#612)', function (): void {
    $runId = qhfpDbStart('qhfp-db-race-', 'collect_failures');
    qhfpDbAdvanceBranch($runId, FailingPromptAgent::class);
    qhfpDbAdvanceBranch($runId, FakeWriter::class);

    // Put the run back at its join, branches terminal, so the race replays the release.
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['status' => 'waiting']);
    expect(app(RunHistoryStore::class)->find($runId)['status'])->toBe('waiting');

    $dir = qhfpDbBarrierDir();

    [$releaser, $first, $second] = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
        qhfpDbHoldingRelease($runId, $dir),
        qhfpDbResumer($runId, $dir, 'resume-a'),
        qhfpDbResumer($runId, $dir, 'resume-b'),
    ]);

    expect($releaser)->toBe(['outcome' => 'released', 'contended' => true, 'locks' => null], json_encode($releaser))
        ->and($first)->toBe(['outcome' => 'returned'], json_encode($first))
        ->and($second)->toBe(['outcome' => 'returned'], json_encode($second));

    $message = qhfpDbExpectedMessage($runId);
    $record = qhfpDbRecord($runId);

    expect($record)->toBe([
        'history_status' => 'failed',
        'history_error' => $message,
        'durable_status' => 'failed',
        'durable_failure' => $message,
        'branches' => ['writer_node' => 'completed', 'failing_node' => 'failed'],
    ]);

    // The release's own queued resume arrives last: a no-op.
    qhfpDbResume($runId);
    expect(qhfpDbRecord($runId))->toBe($record);
});
