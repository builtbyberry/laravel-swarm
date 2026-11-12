<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ChildDispatchingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableWaitAttributeSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\OrdinaryBoundaryHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\PausedWait\PausedWaitWorker;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

// Shared real database, never migrate:fresh. Worker closures have free-function
// scope and use only autoloaded fixtures, as in DurableResumeOpenWaitTest.
pest()->group('process-concurrency', 'skip-locked-real-db');

function pausedCreateRaceDirs(?string $add = null, bool $clear = false): array
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

function pausedCreateRaceDir(): string
{
    $dir = sys_get_temp_dir().'/laravel-swarm-paused-create-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    pausedCreateRaceDirs($dir);

    return $dir;
}

function pausedCreateRaceRun(): string
{
    $runId = FakeSequentialSwarm::make()->dispatchDurable('paused-create-race')->runId;
    app(DurableSwarmManager::class)->pause($runId);

    return $runId;
}

function pausedCreateRaceSteps(string $runId): int
{
    return DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'step')->count();
}

function pausedCreateRaceWorker(string $method, array $arguments): Closure
{
    return static fn (): array => PausedWaitWorker::{$method}(...$arguments);
}

beforeEach(function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
        $this->markTestSkipped('Paused wait/create barriers require shared mysql/pgsql; current driver: '.DB::connection()->getDriverName().'.');
    }
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.durable.queue.connection', 'durable-test');
});

afterEach(function (): void {
    foreach (pausedCreateRaceDirs(clear: true) as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

test('G1 S1 creator holds its inserted wait until resume is blocked then commits', function (): void {
    $runId = pausedCreateRaceRun();
    $dir = pausedCreateRaceDir();
    $before = pausedCreateRaceSteps($runId);
    [$creator, $resumer] = app(ConcurrencyManager::class)->driver('process')->run([
        pausedCreateRaceWorker('creatorFirst', [$runId, $dir]),
        pausedCreateRaceWorker('resumeAfterInsert', [$runId, $dir]),
    ]);

    expect($creator['outcome'])->toBe('created')->and($creator['blocked'])->toBeTrue();
    // MySQL may block on the uncommitted wait, Postgres on the run. Keep the
    // actual engine lock evidence in the assertion output, including lock_data.
    if (DB::connection()->getDriverName() === 'mysql') {
        expect($creator['locks'])->not->toBeEmpty()
            ->and(array_column($creator['locks'], 'object_name'))->each->toBeIn(['swarm_durable_runs', 'swarm_durable_waits']);
    } else {
        expect(implode(' ', array_column($creator['locks'], 'query')))->toContain('swarm_durable_runs');
    }
    expect($resumer)->toBe(['outcome' => 'waiting'])
        ->and(PausedWaitWorker::state($runId))->toBe(['status' => 'waiting', 'open' => 1])
        ->and(app(DurableRunStore::class)->waits($runId)[0]['name'])->toBe('late')
        ->and(pausedCreateRaceSteps($runId))->toBe($before);
});

test('G2(ii) R1 creator commits after the empty scan before resume locks the run under READ COMMITTED', function (): void {
    $runId = pausedCreateRaceRun();
    $dir = pausedCreateRaceDir();
    $before = pausedCreateRaceSteps($runId);
    [$resumer, $creator] = app(ConcurrencyManager::class)->driver('process')->run([
        pausedCreateRaceWorker('scanFirst', [$runId, $dir, 'READ COMMITTED']),
        pausedCreateRaceWorker('createAfterScan', [$runId, $dir, 'READ COMMITTED']),
    ]);
    expect($creator)->toBe(['outcome' => 'created'])
        ->and($resumer['outcome'])->toBe('waiting')
        ->and($resumer['at_commit'])->toBe(['status' => 'waiting', 'open' => 1])
        ->and(PausedWaitWorker::state($runId))->toBe(['status' => 'waiting', 'open' => 1])
        ->and(pausedCreateRaceSteps($runId))->toBe($before);
});

test('G2(iii) MySQL REPEATABLE READ gap/run cycle aborts a victim or completes safely', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL REPEATABLE READ next-key lock cycle only.');
    }
    $runId = pausedCreateRaceRun();
    $dir = pausedCreateRaceDir();
    [$resumer, $creator] = app(ConcurrencyManager::class)->driver('process')->run([
        pausedCreateRaceWorker('scanFirst', [$runId, $dir, 'REPEATABLE READ', true]),
        pausedCreateRaceWorker('createAfterScan', [$runId, $dir, 'REPEATABLE READ', true]),
    ]);
    expect($resumer['blocked'])->toBeTrue();
    foreach ([$resumer, $creator] as $result) {
        if ($result['outcome'] === 'aborted') {
            expect($result['failure'])->toBe('sqlstate:40001');
        } else {
            expect($result['outcome'])->toBeIn(['created', 'resumed', 'waiting']);
        }
    }
    expect(count(array_filter([$resumer, $creator], fn (array $result): bool => $result['outcome'] === 'aborted')))->toBeLessThanOrEqual(1);
    $state = PausedWaitWorker::state($runId);
    expect($state['status'] === 'pending' && $state['open'] > 0)->toBeFalse(json_encode([$resumer, $creator, $state]));
});

test('S2 R1(i) a resumer already owning the run lets later creation restore waiting', function (): void {
    $runId = pausedCreateRaceRun();
    $dir = pausedCreateRaceDir();
    [$resumer, $creator] = app(ConcurrencyManager::class)->driver('process')->run([
        pausedCreateRaceWorker('runFirst', [$runId, $dir]),
        pausedCreateRaceWorker('createAfterRunLock', [$runId, $dir]),
    ]);
    expect($resumer)->toBe(['resumed' => true, 'status' => 'pending', 'blocked' => true])
        ->and($creator)->toBe(['outcome' => 'created'])
        ->and(PausedWaitWorker::state($runId))->toBe(['status' => 'waiting', 'open' => 1]);
});

test('R8 a creator rollback leaves no generation change or wait and resume reaches pending', function (): void {
    $runId = pausedCreateRaceRun();
    $dir = pausedCreateRaceDir();
    $before = pausedCreateRaceSteps($runId);
    [$creator, $resumer] = app(ConcurrencyManager::class)->driver('process')->run([
        pausedCreateRaceWorker('creatorFirst', [$runId, $dir, true]),
        pausedCreateRaceWorker('resumeAfterInsert', [$runId, $dir]),
    ]);
    expect($creator['outcome'])->toBe('rolled-back')->and($creator['blocked'])->toBeTrue()
        ->and($resumer)->toBe(['outcome' => 'resumed'])
        ->and(PausedWaitWorker::state($runId))->toBe(['status' => 'pending', 'open' => 0])
        ->and((string) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('wait_generation'))->toBe('0')
        ->and(pausedCreateRaceSteps($runId))->toBe($before + 1);
});

test('G3 S5 ordinary checkpoint commits its declared boundary before another worker can lease next', function (string $swarm): void {
    $runId = $swarm::make()->dispatchDurable('ordinary-boundary')->runId;
    $dir = pausedCreateRaceDir();
    $before = pausedCreateRaceSteps($runId);
    [$executor, $observer] = app(ConcurrencyManager::class)->driver('process')->run([
        pausedCreateRaceWorker('checkpoint', [$runId, $dir]),
        pausedCreateRaceWorker('observeCheckpoint', [$runId, $dir]),
    ]);
    expect($executor)->toBe(['outcome' => 'checkpointed'])
        ->and($observer)->toBe(['status' => 'waiting', 'open' => 1, 'leased' => false, 'next' => 1])
        ->and(pausedCreateRaceSteps($runId))->toBe($before);
    $wait = app(DurableRunStore::class)->waits($runId)[0];
    expect($wait['status'])->toBe('waiting');
    if ($swarm === ChildDispatchingSwarm::class) {
        expect($wait['name'])->toStartWith('child:')->and(app(DurableRunStore::class)->childRuns($runId))->toHaveCount(1);
    } else {
        expect($wait['name'])->toBe('approval_received');
    }
})->with([DurableWaitAttributeSwarm::class, ChildDispatchingSwarm::class, OrdinaryBoundaryHierarchicalSwarm::class]);

test('G4 create plus close since an application snapshot fails loud only on MySQL REPEATABLE READ', function (string $isolation): void {
    $mysqlRR = DB::connection()->getDriverName() === 'mysql' && $isolation === 'REPEATABLE READ';
    if (DB::connection()->getDriverName() === 'pgsql' && $isolation !== 'READ COMMITTED') {
        $this->markTestSkipped('The Postgres expectation is READ COMMITTED.');
    }
    $runId = pausedCreateRaceRun();
    $dir = pausedCreateRaceDir();
    $before = pausedCreateRaceSteps($runId);
    [$resumer, $creator] = app(ConcurrencyManager::class)->driver('process')->run([
        pausedCreateRaceWorker('snapshotResume', [$runId, $dir, $isolation]),
        pausedCreateRaceWorker('createAndClose', [$runId, $dir, $isolation]),
    ]);
    expect($creator)->toBe(['outcome' => 'created-closed'])
        ->and((string) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('wait_generation'))->toBe('1')
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->where('name', 'transient')->value('status'))->toBe('child_completed');
    if ($mysqlRR) {
        expect($resumer['outcome'])->toBe('aborted')
            ->and($resumer['class'])->toBe(SwarmException::class)
            ->and($resumer['message'])->toContain('retry the resume outside this transaction')
            ->and(PausedWaitWorker::state($runId))->toBe(['status' => 'paused', 'open' => 0])
            ->and(pausedCreateRaceSteps($runId))->toBe($before);
    } else {
        expect($resumer['outcome'])->toBe('resumed')->and($resumer['status'])->toBe('pending')
            ->and(PausedWaitWorker::state($runId))->toBe(['status' => 'pending', 'open' => 0])
            ->and(pausedCreateRaceSteps($runId))->toBe($before + 1);
    }
})->with(['READ COMMITTED', 'REPEATABLE READ']);

test('R6 a signal closer racing an ordinary checkpoint leaves its new declared wait intact without deadlock', function (): void {
    $runId = DurableWaitAttributeSwarm::make()->dispatchDurable('closer-checkpoint')->runId;
    $signal = app(DurableRunStore::class)->recordSignal($runId, 'legacy', ['approved' => true], 'ordinary-closer');
    $dir = pausedCreateRaceDir();
    [$executor, $closer] = app(ConcurrencyManager::class)->driver('process')->run([
        pausedCreateRaceWorker('checkpointAgainstCloser', [$runId, $dir]),
        pausedCreateRaceWorker('signalCloser', [$runId, $dir, $signal['id']]),
    ]);
    expect($executor)->toBe(['outcome' => 'checkpointed', 'blocked' => true])
        ->and($closer)->toBe(['outcome' => 'closed', 'closed' => true])
        ->and(PausedWaitWorker::state($runId))->toBe(['status' => 'waiting', 'open' => 1])
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->orderBy('id')->pluck('status', 'name')->all())
        ->toBe(['legacy' => 'signalled', 'approval_received' => 'waiting']);
});
