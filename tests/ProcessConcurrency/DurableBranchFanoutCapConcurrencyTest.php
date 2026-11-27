<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableOutbox;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchAdmission;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Durable\FanoutCapConcurrency;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Durable\KilledAfterCommitBranchAdmission;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeWideHierarchicalSwarm;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Process-concurrency coverage for swarm.durable.parallel.max_concurrent_branches:
 * real worker processes against a shared MySQL/Postgres database.
 *
 *   - Six workers drain and run a 16-branch fan-out capped at 3. Every agent start
 *     records how many branch agents are executing, and how many branches are
 *     admitted and unfinished, at that instant; neither exceeds 3, and all 16 complete.
 *   - Two reconcilers race on one run: together they release exactly the free slots.
 *   - A lease taken inside a transaction whose snapshot predates the hold still sees
 *     the hold (the held lookup is a locking, current read).
 *   - A worker killed between its branch's commit and the reconcile leaves held
 *     branches that swarm:recover releases — never past the cap.
 */
// Real shared database only: skipped on per-process in-memory SQLite, and excluded
// from the CI lane that fails on skips; run by test:process-concurrency:real-db.
pest()->group('process-concurrency', 'skip-locked-real-db');

function capConcurrencyDriverSupported(): bool
{
    return ! in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true);
}

function capConcurrencyPlan(int $width): void
{
    $nodes = ['parallel_node' => ['type' => 'parallel', 'branches' => [], 'next' => 'finish_node']];

    for ($i = 0; $i < $width; $i++) {
        $nodes['parallel_node']['branches'][] = "w{$i}";
        $nodes["w{$i}"] = ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => "cap-branch-{$i}-end"];
    }

    $nodes['finish_node'] = ['type' => 'finish', 'output_from' => 'w0'];
    FakeHierarchicalCoordinator::fake([['start_at' => 'parallel_node', 'nodes' => $nodes]]);
}

/**
 * A worker that drains the relay and runs whatever it claimed until the run ends.
 */
function capConcurrencyWorker(string $label, string $runId, string $barrierPath, int $cap, int $workers): Closure
{
    return static function () use ($label, $runId, $barrierPath, $cap, $workers): array {
        FanoutCapConcurrency::boot($cap);
        Queue::fake();

        FakeWriter::fake(static function (string $prompt) use ($label, $runId): string {
            preg_match('/cap-branch-(\d+)-end/', $prompt, $m);
            $id = DB::table('cap_race_executions')->insertGetId(['worker' => $label, 'branch' => 'w'.($m[1] ?? '?'), 'running' => 0, 'admitted' => 0]);
            $running = DB::table('cap_race_executions')->whereNull('finished_at')->count();
            DB::table('cap_race_executions')->where('id', $id)->update([
                'running' => $running,
                'admitted' => FanoutCapConcurrency::admittedUnfinished($runId),
            ]);
            // Longer than a second: see DurableEagerDispatchConcurrencyTest (MYSQL-LEASE).
            usleep(1_100_000);
            DB::table('cap_race_executions')->where('id', $id)->update(['finished_at' => now('UTC')]);

            return 'out';
        });

        FanoutCapConcurrency::barrier($barrierPath, $label, $workers);

        $manager = app(DurableSwarmManager::class);
        $outbox = app(DurableOutbox::class);
        $handled = [];
        $deadline = microtime(true) + 45.0;

        while (microtime(true) < $deadline) {
            // Stop once every branch has finished. The join step runs no agent, so on
            // MySQL it can trip MYSQL-LEASE; the run's completion is asserted on Postgres.
            $unfinished = DB::table('swarm_durable_branches')->where('run_id', $runId)
                ->whereNotIn('status', ['completed', 'failed', 'cancelled'])->count();

            if ($unfinished === 0 && in_array($manager->find($runId)['status'] ?? null, ['completed', 'failed', 'cancelled', 'running', 'pending'], true)) {
                break;
            }

            $outbox->drain([], 2);
            $ran = false;

            foreach (Queue::pushedJobs() as $pushes) {
                foreach ($pushes as $push) {
                    $job = $push['job'];

                    if (($job instanceof AdvanceDurableBranch || $job instanceof AdvanceDurableSwarm) && ! isset($handled[spl_object_id($job)])) {
                        $handled[spl_object_id($job)] = true;
                        $job->handle($manager);
                        $ran = true;
                    }
                }
            }

            if (! $ran) {
                usleep(50_000);
            }
        }

        return ['worker' => $label, 'handled' => count($handled)];
    };
}

/**
 * Dispatch a capped run and advance it to its fan-out, relay path.
 */
function capConcurrencyFanOut(int $width): string
{
    // The coordinator and fan-out steps finish fast, which on MySQL trips the
    // pre-existing MYSQL-LEASE finding (a step finishing in the second it started is
    // dropped). The parent's setup runs on a clock that ticks a second per read so
    // no two writes share a second. Workers run on real time.
    $tick = Carbon::now('UTC');
    Carbon::setTestNow(static function () use (&$tick) {
        return $tick = $tick->copy()->addSecond();
    });

    try {
        $runId = capConcurrencyFanOutNow($width);
    } finally {
        Carbon::setTestNow();
    }

    // Bring what the ticking clock wrote back to real time so the workers see it:
    // admitted rows due now, the run's leases/timestamps not in the future.
    $now = Carbon::now('UTC');
    DB::table('swarm_durable_outbox')->where('run_id', $runId)
        ->where(fn ($q) => DatabaseDurableOutbox::constrainNotHeld($q))
        ->update(['available_at' => $now, 'created_at' => $now]);
    DB::table('swarm_durable_branches')->where('run_id', $runId)->update(['created_at' => $now, 'updated_at' => $now]);
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['updated_at' => $now]);

    return $runId;
}

function capConcurrencyFanOutNow(int $width): string
{
    capConcurrencyPlan($width);
    $runId = FakeWideHierarchicalSwarm::make()->dispatchDurable('cap-race')->runId;
    $manager = app(DurableSwarmManager::class);
    $handled = [];

    for ($i = 0; $i < 10 && DB::table('swarm_durable_branches')->where('run_id', $runId)->count() === 0; $i++) {
        app(DurableOutbox::class)->drain();

        foreach (Queue::pushedJobs() as $pushes) {
            foreach ($pushes as $push) {
                if ($push['job'] instanceof AdvanceDurableSwarm && ! isset($handled[spl_object_id($push['job'])])) {
                    $handled[spl_object_id($push['job'])] = true;
                    $push['job']->handle($manager);
                }
            }
        }
    }

    // The workers own everything from here: settle what the parent drained.
    DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'step')->delete();

    return $runId;
}

function capConcurrencyAdmitted(string $runId): int
{
    $held = array_map(
        fn (object $row): string => json_decode((string) $row->payload, true)['branch_id'],
        DatabaseDurableOutbox::constrainHeld(DB::table('swarm_durable_outbox'))->where('run_id', $runId)->get()->all(),
    );

    return DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->whereNotIn('status', ['completed', 'failed', 'cancelled'])
        ->pluck('branch_id')
        ->reject(fn (string $id): bool => in_array($id, $held, true))
        ->count();
}

function capConcurrencyTempDir(string $prefix): string
{
    $path = sys_get_temp_dir().'/laravel-swarm-'.$prefix.'-'.bin2hex(random_bytes(6));
    mkdir($path);

    return $path;
}

function capConcurrencyCleanup(string $path): void
{
    array_map('unlink', glob($path.'/*') ?: []);
    rmdir($path);
}

function capReconcilerWorker(string $label, array $runIds, string $barrier): Closure
{
    return static function () use ($label, $runIds, $barrier): array {
        FanoutCapConcurrency::boot(2);
        Queue::fake();
        FanoutCapConcurrency::barrier($barrier, $label, 2);
        $released = 0;

        foreach ($runIds as $runId) {
            $released += app(DurableBranchAdmission::class)->reconcile($runId);
        }

        return ['released' => $released];
    };
}

function capSnapshotWorker(string $runId, string $barrier, string $go): Closure
{
    return static function () use ($runId, $barrier, $go): array {
        FanoutCapConcurrency::boot(4);
        $outbox = app(DurableOutbox::class);

        return DB::transaction(function () use ($runId, $barrier, $go, $outbox): array {
            // Establish this transaction's snapshot before the hold exists.
            $before = $outbox->heldBranches($runId);
            file_put_contents($barrier.'/ready-worker', 'ready');
            $deadline = microtime(true) + 15.0;

            while (! file_exists($go) && microtime(true) < $deadline) {
                usleep(1_000);
            }

            // The advancer's lease transaction, nested in this older-snapshot one.
            return DB::transaction(function () use ($runId, $before, $outbox): array {
                $token = app(DurableRunStore::class)->acquireBranchLease($runId, 'parallel_node:w9', 60);

                return [
                    'before' => $before,
                    'leased' => $token !== null,
                    'plain_read' => array_values($outbox->heldBranches($runId)),
                    'needs_check' => app(DurableBranchAdmission::class)->leaseNeedsHeldCheck($runId),
                    'held' => app(DurableBranchAdmission::class)->isHeld($runId, 'parallel_node:w9'),
                ];
            });
        });
    };
}

function capSnapshotFanOut(string $runId, string $barrier, string $go): Closure
{
    return static function () use ($runId, $barrier, $go): array {
        FanoutCapConcurrency::boot(4);
        $deadline = microtime(true) + 15.0;

        while (! file_exists($barrier.'/ready-worker') && microtime(true) < $deadline) {
            usleep(1_000);
        }

        // A next-pass fan-out: the branch row and its hold commit together.
        DB::transaction(function () use ($runId): void {
            DB::table('swarm_durable_branches')->insert([
                'run_id' => $runId, 'branch_id' => 'parallel_node:w9', 'step_index' => 1, 'node_id' => 'w9',
                'agent_class' => FakeWriter::class, 'parent_node_id' => 'parallel_node', 'status' => 'pending',
                'input' => 'x', 'metadata' => '[]', 'attempts' => 0,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            app(DurableOutbox::class)->holdBranch($runId, 'parallel_node:w9', null, null);
        });

        file_put_contents($go, 'go');

        return ['fanout' => true];
    };
}

function capVictimWorker(string $runId): Closure
{
    return static function () use ($runId): array {
        FanoutCapConcurrency::boot(2);
        Queue::fake();
        FakeWriter::fake([static function (): string {
            usleep(1_100_000);

            return 'out';
        }]);

        // Die between the branch's committed terminal write and its reconcile.
        app()->bind(DurableBranchAdmission::class, KilledAfterCommitBranchAdmission::class);
        app()->forgetInstance(DurableSwarmManager::class);

        (new AdvanceDurableBranch($runId, 'parallel_node:w0'))->handle(app(DurableSwarmManager::class));

        return ['survived' => true];
    };
}

beforeEach(function (): void {
    if (! capConcurrencyDriverSupported()) {
        $this->markTestSkipped(
            'Fan-out cap concurrency tests require a shared database engine reachable from child processes '
            .'(mysql/pgsql). Current driver: '.DB::connection()->getDriverName().'.'
        );
    }

    FanoutCapConcurrency::boot(4);
    Queue::fake();
    DB::table('swarm_durable_outbox')->delete();

    Schema::dropIfExists('cap_race_executions');
    Schema::create('cap_race_executions', function (Blueprint $table): void {
        $table->id();
        $table->string('worker');
        $table->string('branch');
        $table->unsignedInteger('running');
        $table->unsignedInteger('admitted');
        $table->timestamp('finished_at')->nullable();
    });
});

afterEach(function (): void {
    if (capConcurrencyDriverSupported()) {
        Schema::dropIfExists('cap_race_executions');
    }
});

test('six workers running a 16-branch fan-out capped at 3 never execute or admit more than 3 branches at once', function (): void {
    FakeWriter::fake(['unused']);
    config()->set('swarm.durable.parallel.max_concurrent_branches', 3);
    $runId = capConcurrencyFanOut(16);

    expect(capConcurrencyAdmitted($runId))->toBe(3);

    $barrier = capConcurrencyTempDir('cap-race');

    try {
        // More workers than the cap: only the cap can keep concurrency at 3.
        $results = $this->app->make(ConcurrencyManager::class)->driver('process')->run(array_map(
            fn (string $label): Closure => capConcurrencyWorker($label, $runId, $barrier, 3, 6),
            ['a', 'b', 'c', 'd', 'e', 'f'],
        ));
    } finally {
        capConcurrencyCleanup($barrier);
    }

    $executions = DB::table('cap_race_executions')->get();

    expect($executions)->toHaveCount(16)
        ->and($executions->pluck('branch')->unique())->toHaveCount(16)
        ->and($executions->max('running'))->toBeLessThanOrEqual(3)
        ->and($executions->max('admitted'))->toBeLessThanOrEqual(3)
        ->and($executions->max('running'))->toBeGreaterThan(1)
        ->and(DB::table('swarm_durable_branches')->where('run_id', $runId)->where('status', 'completed')->count())->toBe(16)
        ->and(array_sum(array_column($results, 'handled')))->toBeGreaterThanOrEqual(16);
});

test('two reconcilers racing on one run release exactly the free slots between them', function (): void {
    FakeWriter::fake(['unused']);
    config()->set('swarm.durable.parallel.max_concurrent_branches', 2);
    $runIds = [];

    for ($i = 0; $i < 4; $i++) {
        $runId = capConcurrencyFanOut(8);
        // Both admitted branches finish: two slots are free, six branches are held.
        DB::table('swarm_durable_branches')->where('run_id', $runId)->whereIn('branch_id', ['parallel_node:w0', 'parallel_node:w1'])->update(['status' => 'completed']);
        $runIds[] = $runId;
    }

    $barrier = capConcurrencyTempDir('cap-reconcile');

    try {
        $results = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
            capReconcilerWorker('a', $runIds, $barrier),
            capReconcilerWorker('b', $runIds, $barrier),
        ]);
    } finally {
        capConcurrencyCleanup($barrier);
    }

    expect(array_sum(array_column($results, 'released')))->toBe(8);

    foreach ($runIds as $runId) {
        expect(capConcurrencyAdmitted($runId))->toBe(2);
    }
});

test('a lease inside a transaction whose snapshot predates the hold still sees the hold', function (): void {
    FakeWriter::fake(['unused']);
    capConcurrencyPlan(4);
    $runId = FakeWideHierarchicalSwarm::make()->dispatchDurable('cap-snapshot')->runId;
    $barrier = capConcurrencyTempDir('cap-snapshot');
    $go = $barrier.'/go';

    try {
        $results = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
            capSnapshotWorker($runId, $barrier, $go),
            capSnapshotFanOut($runId, $barrier, $go),
        ]);
    } finally {
        capConcurrencyCleanup($barrier);
    }

    expect($results[0]['before'])->toBe([])
        ->and($results[0]['needs_check'])->toBeTrue()
        ->and($results[0]['held'])->toBeTrue();

    if (DB::connection()->getDriverName() === 'mysql') {
        // The ordinary read is still on the old snapshot: only the locking read sees the hold.
        expect($results[0]['plain_read'])->toBe([]);
    }
});

test('a worker killed after its branch commits leaves held branches that recover releases, within the cap', function (): void {
    FakeWriter::fake(['unused']);
    config()->set('swarm.durable.parallel.max_concurrent_branches', 2);
    $runId = capConcurrencyFanOut(6);

    $victim = capVictimWorker($runId);

    try {
        $this->app->make(ConcurrencyManager::class)->driver('process')->run([$victim]);
    } catch (Throwable) {
        // The child was killed on purpose.
    }

    $statuses = DB::table('swarm_durable_branches')->where('run_id', $runId)->pluck('status', 'branch_id');

    expect($statuses['parallel_node:w0'])->toBe('completed')
        ->and(capConcurrencyAdmitted($runId))->toBe(1);

    app(DurableSwarmManager::class)->recover();

    expect(capConcurrencyAdmitted($runId))->toBe(2);
});
