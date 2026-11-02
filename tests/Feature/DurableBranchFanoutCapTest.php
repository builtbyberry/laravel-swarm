<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\DurableDispatchType;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableOutbox;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchAdmission;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeWideHierarchicalSwarm;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * swarm.durable.parallel.max_concurrent_branches: a capped step-durable hierarchical
 * fan-out admits at most N branches at once — admitted and unfinished — releases a
 * held branch into each freed slot, and still completes every branch.
 */
final class CapProbe
{
    /** @var list<string> agent starts, by branch id */
    public static array $starts = [];

    public static int $maxAdmitted = 0;

    public static ?string $runId = null;

    public static ?int $cap = null;

    /** @var list<string> branch ids whose agent throws */
    public static array $failing = [];

    /** @var list<string> */
    public static array $violations = [];

    public static function reset(): void
    {
        self::$starts = [];
        self::$maxAdmitted = 0;
        self::$runId = null;
        self::$cap = null;
        self::$failing = [];
        self::$violations = [];
    }
}

/**
 * Outbox that can be told to fail a release, to prove a failed reconcile never
 * touches the branch that just finished.
 */
final class CapFlakyOutbox extends DatabaseDurableOutbox
{
    public static int $failReleases = 0;

    public function releaseHeldBranches(array $entryIds, callable $directFor): array
    {
        if (self::$failReleases > 0 && $entryIds !== []) {
            self::$failReleases--;

            throw new RuntimeException('release failed');
        }

        return parent::releaseHeldBranches($entryIds, $directFor);
    }
}

/**
 * Outbox whose release writes, then fails before the transaction commits.
 */
final class CapRollbackOutbox extends DatabaseDurableOutbox
{
    public function releaseHeldBranches(array $entryIds, callable $directFor): array
    {
        parent::releaseHeldBranches($entryIds, $directFor);

        throw new RuntimeException('release failed after writing');
    }
}

function capConfigureRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    capForgetRuntime();
}

function capForgetRuntime(): void
{
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(DurableOutbox::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(QueuedHierarchicalCoordinator::class);
    app()->forgetInstance(DurableSwarmManager::class);
}

function capSet(mixed $cap): void
{
    config()->set('swarm.durable.parallel.max_concurrent_branches', $cap);
}

/**
 * A hierarchical plan whose parallel node fans out to $width FakeWriter workers.
 */
function capPlan(int $width): void
{
    $nodes = ['parallel_node' => ['type' => 'parallel', 'branches' => [], 'next' => 'finish_node']];

    for ($i = 0; $i < $width; $i++) {
        $nodes['parallel_node']['branches'][] = "w{$i}";
        $nodes["w{$i}"] = ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => "cap-branch-{$i}-end"];
    }

    $nodes['finish_node'] = ['type' => 'finish', 'output_from' => 'w0'];

    FakeHierarchicalCoordinator::fake([['start_at' => 'parallel_node', 'nodes' => $nodes]]);
}

function capHeldRows(?string $runId = null): array
{
    return DatabaseDurableOutbox::constrainHeld(DB::table('swarm_durable_outbox'))
        ->when($runId !== null, fn ($q) => $q->where('run_id', $runId))
        ->orderBy('id')
        ->get()
        ->all();
}

/**
 * @return list<string>
 */
function capHeldBranchIds(string $runId): array
{
    return array_map(
        fn (object $row): string => json_decode($row->payload, true)['branch_id'],
        capHeldRows($runId),
    );
}

/**
 * Admitted and unfinished branches of the run's fan-out — what the cap bounds.
 */
function capAdmittedUnfinished(string $runId): int
{
    $held = capHeldBranchIds($runId);

    return DB::table('swarm_durable_branches')
        ->where('run_id', $runId)
        ->whereNotIn('status', ['completed', 'failed', 'cancelled'])
        ->pluck('branch_id')
        ->reject(fn (string $id): bool => in_array($id, $held, true))
        ->count();
}

function capBranchStatuses(string $runId): array
{
    return DB::table('swarm_durable_branches')->where('run_id', $runId)->orderBy('id')->pluck('status', 'branch_id')->all();
}

function capFakeWriter(): void
{
    FakeWriter::fake(function (string $prompt): string {
        preg_match('/cap-branch-(\d+)-end/', $prompt, $m);
        $branchId = 'parallel_node:w'.($m[1] ?? '?');
        CapProbe::$starts[] = $branchId;

        if (CapProbe::$runId !== null) {
            if (in_array($branchId, capHeldBranchIds(CapProbe::$runId), true)) {
                CapProbe::$violations[] = "held branch {$branchId} started";
            }

            $admitted = capAdmittedUnfinished(CapProbe::$runId);
            CapProbe::$maxAdmitted = max(CapProbe::$maxAdmitted, $admitted);

            if (CapProbe::$cap !== null && $admitted > CapProbe::$cap) {
                CapProbe::$violations[] = "{$admitted} admitted unfinished at start of {$branchId}";
            }
        }

        if (in_array($branchId, CapProbe::$failing, true)) {
            throw new RuntimeException("branch {$branchId} failed");
        }

        return "out-{$branchId}";
    });
}

/**
 * Every queued advance job not yet handled.
 *
 * @param  array<int, true>  $handled
 * @return list<AdvanceDurableSwarm|AdvanceDurableBranch>
 */
function capPendingJobs(array $handled): array
{
    $jobs = [];

    foreach (Queue::pushedJobs() as $pushes) {
        foreach ($pushes as $push) {
            $job = $push['job'];

            if (($job instanceof AdvanceDurableSwarm || $job instanceof AdvanceDurableBranch) && ! isset($handled[spl_object_id($job)])) {
                $jobs[] = $job;
            }
        }
    }

    return $jobs;
}

/**
 * Drive the run like a queue worker would, one job at a time: step jobs first,
 * branch jobs in the given order. With $relay, the outbox is drained before each
 * pick (relay path); otherwise jobs arrive by eager push. After every job the
 * admitted-unfinished count is checked against the cap.
 *
 * @param  callable(string, int): void|null  $between  called after every handled job
 */
function capDrive(string $runId, string $order = 'fifo', bool $relay = true, ?callable $between = null): void
{
    $manager = app(DurableSwarmManager::class);
    $handled = [];
    mt_srand(1729);

    for ($i = 0; $i < 400; $i++) {
        if ($relay) {
            app(DurableOutbox::class)->drain();
        }

        $jobs = capPendingJobs($handled);

        if ($jobs === []) {
            return;
        }

        $steps = array_values(array_filter($jobs, fn (object $job): bool => $job instanceof AdvanceDurableSwarm));
        $branches = array_values(array_filter($jobs, fn (object $job): bool => $job instanceof AdvanceDurableBranch));

        $job = match (true) {
            $steps !== [] => $steps[0],
            $order === 'lifo' => $branches[count($branches) - 1],
            $order === 'random' => $branches[mt_rand(0, count($branches) - 1)],
            default => $branches[0],
        };

        $handled[spl_object_id($job)] = true;
        $job->handle($manager);

        if (CapProbe::$cap !== null && capAdmittedUnfinished($runId) > CapProbe::$cap) {
            CapProbe::$violations[] = 'over cap after a job';
        }

        if ($between !== null) {
            $between($runId, $i);
        }
    }

    throw new RuntimeException('capDrive did not settle');
}

/**
 * Dispatch a $width-wide run and advance it to its fan-out.
 */
function capStartFanOut(int $width, bool $relay = true): string
{
    capPlan($width);
    $runId = FakeWideHierarchicalSwarm::make()->dispatchDurable('cap-task')->runId;
    CapProbe::$runId = $runId;
    $manager = app(DurableSwarmManager::class);
    $handled = [];

    for ($i = 0; $i < 10 && DB::table('swarm_durable_branches')->where('run_id', $runId)->count() === 0; $i++) {
        if ($relay) {
            app(DurableOutbox::class)->drain();
        }

        foreach (capPendingJobs($handled) as $job) {
            $handled[spl_object_id($job)] = true;

            if ($job instanceof AdvanceDurableSwarm) {
                $job->handle($manager);
            }
        }
    }

    return $runId;
}

beforeEach(function (): void {
    capConfigureRuntime();
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Queue::fake();
    CapProbe::reset();
    CapFlakyOutbox::$failReleases = 0;
    capFakeWriter();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Default: unlimited, exactly as before
// ---------------------------------------------------------------------------

test('null cap: every branch is dispatched at the fan-out and none is held', function (): void {
    capSet(null);
    $runId = capStartFanOut(16);

    $rows = DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'branch')->get();

    expect($rows)->toHaveCount(16)
        ->and(capHeldRows($runId))->toBe([])
        ->and($rows->every(fn (object $row): bool => (string) $row->available_at <= now('UTC')->format('Y-m-d H:i:s')))->toBeTrue();

    capDrive($runId);

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('completed')
        ->and(array_values(array_unique(capBranchStatuses($runId))))->toBe(['completed'])
        ->and(CapProbe::$maxAdmitted)->toBe(16);
});

test('an empty cap value means unlimited', function (): void {
    capSet('');
    $runId = capStartFanOut(16);

    expect(capHeldRows($runId))->toBe([]);
});

// ---------------------------------------------------------------------------
// Cap 4 x 16: never more than 4 admitted and unfinished, and all 16 complete
// ---------------------------------------------------------------------------

test('cap 4 on the relay path: 4 dispatched, 12 held, never more than 4 admitted, all 16 complete', function (string $order): void {
    capSet(4);
    CapProbe::$cap = 4;
    $runId = capStartFanOut(16);

    expect(capHeldRows($runId))->toHaveCount(12)
        ->and(DB::table('swarm_durable_branches')->where('run_id', $runId)->count())->toBe(16)
        ->and(capAdmittedUnfinished($runId))->toBe(4);

    capDrive($runId, $order);

    expect(CapProbe::$violations)->toBe([])
        ->and(CapProbe::$maxAdmitted)->toBe(4)
        ->and(array_unique(CapProbe::$starts))->toHaveCount(16)
        ->and(array_values(array_unique(capBranchStatuses($runId))))->toBe(['completed'])
        ->and(capHeldRows($runId))->toBe([])
        ->and(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('completed');
})->with(['fifo', 'lifo', 'random']);

test('cap 4 on the eager path: pushes stay within the cap and all 16 complete', function (string $order): void {
    config()->set('swarm.durable.eager_dispatch', true);
    capSet(4);
    CapProbe::$cap = 4;
    $runId = capStartFanOut(16, relay: false);

    expect(capHeldRows($runId))->toHaveCount(12);

    $pushedBranches = fn (): int => count(array_filter(capPendingJobs([]), fn (object $job): bool => $job instanceof AdvanceDurableBranch && $job->runId === $runId));
    expect($pushedBranches())->toBe(4);

    capDrive($runId, $order, relay: false);

    expect(CapProbe::$violations)->toBe([])
        ->and(CapProbe::$maxAdmitted)->toBe(4)
        ->and($pushedBranches())->toBe(16)
        ->and(array_values(array_unique(capBranchStatuses($runId))))->toBe(['completed'])
        ->and(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('completed');
})->with(['fifo', 'lifo', 'random']);

test('mixed: the eager flag flipped mid-run routes each release by the flag at release time', function (bool $eagerAtFanOut): void {
    config()->set('swarm.durable.eager_dispatch', $eagerAtFanOut);
    capSet(4);
    CapProbe::$cap = 4;
    $runId = capStartFanOut(16, relay: ! $eagerAtFanOut);

    // Flip after every few jobs; drain the relay each pick so relay rows also move.
    capDrive($runId, 'fifo', relay: true, between: function (string $runId, int $i): void {
        if ($i % 3 === 2) {
            config()->set('swarm.durable.eager_dispatch', ! config('swarm.durable.eager_dispatch'));
        }
    });

    expect(CapProbe::$violations)->toBe([])
        ->and(CapProbe::$maxAdmitted)->toBe(4)
        ->and(array_values(array_unique(capBranchStatuses($runId))))->toBe(['completed'])
        ->and(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('completed');
})->with(['eager at fan-out' => true, 'relay at fan-out' => false]);

test('two runs, one eager and one relay, each keep their own cap', function (): void {
    capSet(3);
    CapProbe::$cap = 3;

    config()->set('swarm.durable.eager_dispatch', true);
    $eagerRun = capStartFanOut(8, relay: false);
    config()->set('swarm.durable.eager_dispatch', false);
    capPlan(8);
    $relayRun = FakeWideHierarchicalSwarm::make()->dispatchDurable('cap-task')->runId;
    CapProbe::$runId = null;

    capDrive($relayRun, 'fifo', relay: true, between: function () use ($eagerRun, $relayRun): void {
        foreach ([$eagerRun, $relayRun] as $runId) {
            if (capAdmittedUnfinished($runId) > 3) {
                CapProbe::$violations[] = "run {$runId} over cap";
            }
        }
    });

    expect(CapProbe::$violations)->toBe([])
        ->and(app(DurableSwarmManager::class)->find($eagerRun)['status'])->toBe('completed')
        ->and(app(DurableSwarmManager::class)->find($relayRun)['status'])->toBe('completed');
});

// ---------------------------------------------------------------------------
// The join and failure policies are unchanged
// ---------------------------------------------------------------------------

test('the join waits for every branch: completing the admitted ones does not release the parent', function (): void {
    capSet(4);
    $runId = capStartFanOut(16);
    $manager = app(DurableSwarmManager::class);

    foreach (['w0', 'w1', 'w2', 'w3'] as $node) {
        (new AdvanceDurableBranch($runId, "parallel_node:{$node}"))->handle($manager);
    }

    expect(array_count_values(capBranchStatuses($runId)))->toBe(['completed' => 4, 'pending' => 12])
        ->and($manager->find($runId)['status'])->toBe('waiting')
        ->and(capHeldRows($runId))->toHaveCount(8);
});

test('a failing branch frees its slot and the run ends exactly as an uncapped run does', function (string $policy): void {
    config()->set('swarm.durable.parallel.failure_policy', $policy);
    CapProbe::$failing = ['parallel_node:w2'];

    capSet(null);
    $uncapped = capStartFanOut(8);
    capDrive($uncapped);
    $uncappedRun = app(DurableSwarmManager::class)->find($uncapped);

    capSet(2);
    CapProbe::$cap = 2;
    $capped = capStartFanOut(8);
    capDrive($capped);
    $cappedRun = app(DurableSwarmManager::class)->find($capped);

    expect(CapProbe::$violations)->toBe([])
        ->and($cappedRun['status'])->toBe($uncappedRun['status'])
        ->and(capBranchStatuses($capped))->toBe(capBranchStatuses($uncapped))
        ->and(capHeldRows($capped))->toBe([]);
})->with(['collect_failures', 'partial_success']);

test('fail_run: held branches are cancelled with the parent and their rows are discarded', function (): void {
    config()->set('swarm.durable.parallel.failure_policy', 'fail_run');
    CapProbe::$failing = ['parallel_node:w0'];
    capSet(2);
    $runId = capStartFanOut(6);

    capDrive($runId);

    $statuses = capBranchStatuses($runId);
    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('failed')
        ->and($statuses['parallel_node:w5'])->toBe('cancelled')
        ->and(array_intersect(CapProbe::$starts, ['parallel_node:w4', 'parallel_node:w5']))->toBe([]);

    app(DurableSwarmManager::class)->recover();

    expect(capHeldRows($runId))->toBe([]);
});

// ---------------------------------------------------------------------------
// Execution-time admission: no job can run a held branch
// ---------------------------------------------------------------------------

test('a stray job for a held branch does not lease it, settle anything or start its agent', function (bool $withEntry): void {
    capSet(4);
    $runId = capStartFanOut(8);
    $heldRow = capHeldRows($runId)[0];
    $branchId = json_decode($heldRow->payload, true)['branch_id'];

    (new AdvanceDurableBranch($runId, $branchId, $withEntry ? (int) $heldRow->id : null))->handle(app(DurableSwarmManager::class));

    $branch = DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', $branchId)->first();
    expect(CapProbe::$starts)->toBe([])
        ->and($branch->status)->toBe('pending')
        ->and((int) $branch->attempts)->toBe(0)
        ->and($branch->execution_token)->toBeNull()
        ->and($branch->leased_until)->toBeNull()
        ->and(capRowExists((int) $heldRow->id))->toBeTrue()
        ->and(capHeldBranchIds($runId))->toContain($branchId);
})->with(['without an outbox entry' => false, 'carrying the held row id' => true]);

function capRowExists(int $id): bool
{
    return DB::table('swarm_durable_outbox')->where('id', $id)->exists();
}

test('a held check inside an enclosing application transaction still refuses the lease', function (): void {
    capSet(4);
    $runId = capStartFanOut(8);
    $branchId = capHeldBranchIds($runId)[0];

    DB::transaction(function () use ($runId, $branchId): void {
        (new AdvanceDurableBranch($runId, $branchId))->handle(app(DurableSwarmManager::class));
    });

    expect(CapProbe::$starts)->toBe([])
        ->and((int) DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', $branchId)->value('attempts'))->toBe(0);
});

// ---------------------------------------------------------------------------
// Reconcile: idempotent, outside the terminal write, healed by recovery
// ---------------------------------------------------------------------------

test('a failed release after a branch completes leaves that branch completed, and recover releases later', function (): void {
    app()->singleton(DurableOutbox::class, CapFlakyOutbox::class);
    app()->forgetInstance(DurableSwarmManager::class);
    capSet(2);
    $runId = capStartFanOut(4);
    CapFlakyOutbox::$failReleases = 1;

    (new AdvanceDurableBranch($runId, 'parallel_node:w0'))->handle(app(DurableSwarmManager::class));

    expect(capBranchStatuses($runId)['parallel_node:w0'])->toBe('completed')
        ->and(capHeldRows($runId))->toHaveCount(2);

    app(DurableSwarmManager::class)->recover();

    expect(capHeldRows($runId))->toHaveCount(1)
        ->and(capAdmittedUnfinished($runId))->toBe(2);
});

test('reconcile is idempotent: running it again never admits past the cap', function (): void {
    capSet(4);
    $runId = capStartFanOut(16);
    (new AdvanceDurableBranch($runId, 'parallel_node:w0'))->handle(app(DurableSwarmManager::class));

    app(DurableSwarmManager::class)->recover();
    app(DurableSwarmManager::class)->recover();

    expect(capAdmittedUnfinished($runId))->toBe(4)
        ->and(capHeldRows($runId))->toHaveCount(11);
});

test('a reconcile scheduled inside a transaction runs after the outermost commit and is dropped on rollback', function (): void {
    capSet(1);
    $runId = capStartFanOut(3);
    DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', 'parallel_node:w0')->update(['status' => 'completed']);
    $admission = app(DurableBranchAdmission::class);

    try {
        DB::transaction(function () use ($admission, $runId): void {
            $admission->reconcileAfterCommit($runId);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(capHeldRows($runId))->toHaveCount(2);

    DB::transaction(function () use ($admission, $runId): void {
        DB::transaction(fn () => $admission->reconcileAfterCommit($runId));

        expect(capHeldRows($runId))->toHaveCount(2);
    });

    expect(capHeldRows($runId))->toHaveCount(1);
});

test('turning the cap off mid-run releases every held branch at the next reconcile', function (): void {
    capSet(4);
    $runId = capStartFanOut(16);
    capSet(null);

    (new AdvanceDurableBranch($runId, 'parallel_node:w0'))->handle(app(DurableSwarmManager::class));

    expect(capHeldRows($runId))->toBe([]);

    capDrive($runId);

    expect(app(DurableSwarmManager::class)->find($runId)['status'])->toBe('completed');
});

test('a held row whose branch already finished is discarded, not counted, and the slot goes to a real held branch', function (): void {
    capSet(2);
    $runId = capStartFanOut(5);
    // Older code ran held w2 after a rollback, then the app rolled forward.
    DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', 'parallel_node:w2')->update(['status' => 'completed']);

    (new AdvanceDurableBranch($runId, 'parallel_node:w0'))->handle(app(DurableSwarmManager::class));

    expect(capHeldBranchIds($runId))->toBe(['parallel_node:w4'])
        ->and(capAdmittedUnfinished($runId))->toBe(2);
});

// ---------------------------------------------------------------------------
// Resume and recovery never bypass the cap
// ---------------------------------------------------------------------------

test('resume redispatches admitted branches only, and reconciles', function (): void {
    capSet(4);
    $runId = capStartFanOut(16);
    $manager = app(DurableSwarmManager::class);
    $before = count(capPendingJobs([]));

    $manager->pause($runId);
    $manager->resume($runId);

    app(DurableOutbox::class)->drain();
    $drained = array_slice(capPendingJobs([]), $before);
    $branchIds = array_unique(array_map(fn (object $job): string => $job->branchId, array_filter($drained, fn (object $job): bool => $job instanceof AdvanceDurableBranch)));
    sort($branchIds);

    expect($branchIds)->toBe(['parallel_node:w0', 'parallel_node:w1', 'parallel_node:w2', 'parallel_node:w3'])
        ->and(capHeldRows($runId))->toHaveCount(12);
});

test('recovery skips held branches before its limit, so a crashed admitted branch behind them is recovered', function (): void {
    capSet(1);
    $runId = capStartFanOut(6);
    $otherRunId = capStartFanOut(2);
    $old = now('UTC')->subHour();

    // Held branches are the oldest rows; the admitted w0 crashed later, then the other run's w0.
    DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', '!=', 'parallel_node:w0')->update(['updated_at' => $old->copy()->subMinutes(30)]);
    DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', 'parallel_node:w0')->update(['status' => 'running', 'leased_until' => $old, 'updated_at' => $old]);
    DB::table('swarm_durable_branches')->where('run_id', $otherRunId)->where('branch_id', 'parallel_node:w0')->update(['status' => 'running', 'leased_until' => $old, 'updated_at' => $old->copy()->addMinute()]);
    $before = count(capPendingJobs([]));

    app(DurableSwarmManager::class)->recover(limit: 1);
    app(DurableSwarmManager::class)->recover(limit: 1);

    $recovered = array_map(
        fn (AdvanceDurableBranch $job): string => $job->runId.'|'.$job->branchId,
        array_values(array_filter(array_slice(capPendingJobs([]), $before), fn (object $job): bool => $job instanceof AdvanceDurableBranch)),
    );

    expect($recovered)->toContain($runId.'|parallel_node:w0')
        ->and($recovered)->toContain($otherRunId.'|parallel_node:w0')
        ->and(array_filter($recovered, fn (string $id): bool => str_starts_with($id, $runId.'|') && $id !== $runId.'|parallel_node:w0'))->toBe([]);
});

test('recover without the bundled persistence pair behaves exactly as before', function (): void {
    capSet(null);
    app()->bind(DurableOutbox::class, fn () => Mockery::mock(DurableOutbox::class)->shouldIgnoreMissing());
    app()->forgetInstance(DurableSwarmManager::class);
    DB::statement('drop table swarm_durable_outbox');

    expect(app(DurableSwarmManager::class)->recover())->toBe([]);
});

// ---------------------------------------------------------------------------
// The held marker is reserved and never comes due
// ---------------------------------------------------------------------------

test('past the run deadline and past the hold marker, nothing admits a held branch', function (string $at): void {
    capSet(4);
    $runId = capStartFanOut(8);

    Carbon::setTestNow(Carbon::parse($at, 'UTC'));

    for ($i = 0; $i < 3; $i++) {
        app(DurableOutbox::class)->drain();
    }

    $drainedBranches = array_filter(capPendingJobs([]), fn (object $job): bool => $job instanceof AdvanceDurableBranch);
    $branchIds = array_unique(array_map(fn (AdvanceDurableBranch $job): string => $job->branchId, $drainedBranches));
    sort($branchIds);

    expect($branchIds)->toBe(['parallel_node:w0', 'parallel_node:w1', 'parallel_node:w2', 'parallel_node:w3'])
        ->and(capHeldRows($runId))->toHaveCount(4);
})->with([
    'past the run timeout' => '+2 days',
    'exactly at the marker' => DatabaseDurableOutbox::HOLD_UNTIL,
    'at the marker with microseconds' => DatabaseDurableOutbox::HOLD_UNTIL.'.500000',
    'after the marker' => '2038-01-02 00:00:00',
]);

test('no ordinary write stores the hold marker, even in its own second', function (bool $eager): void {
    config()->set('swarm.durable.eager_dispatch', $eager);
    capSet(1);
    $runId = capStartFanOut(3);
    DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', 'parallel_node:w0')->update(['status' => 'completed']);
    Carbon::setTestNow(Carbon::parse(DatabaseDurableOutbox::HOLD_UNTIL.'.250000', 'UTC'));

    $stepEntry = app(DurableOutbox::class)->enqueueStep($runId, 9, null, null, $eager);
    $branchEntry = app(DurableOutbox::class)->enqueueBranch($runId, 'parallel_node:w0', null, null, $eager);
    app(DurableBranchAdmission::class)->reconcile($runId);

    foreach ([$stepEntry, $branchEntry] as $id) {
        expect((string) DB::table('swarm_durable_outbox')->where('id', $id)->value('available_at'))->not->toBe(DatabaseDurableOutbox::HOLD_UNTIL);
    }

    expect(capHeldRows($runId))->toHaveCount(1);
})->with(['relay' => false, 'eager' => true]);

test('a step row at the hold marker second is never treated as held', function (): void {
    $runId = FakeSequentialSwarm::make()->dispatchDurable('task')->runId;
    $id = DB::table('swarm_durable_outbox')->insertGetId([
        'run_id' => $runId, 'dispatch_type' => DurableDispatchType::Step->value, 'payload' => json_encode(['step_index' => 0]),
        'queue_connection' => null, 'queue_name' => null,
        'available_at' => DatabaseDurableOutbox::HOLD_UNTIL, 'reserved_at' => null, 'created_at' => DatabaseDurableOutbox::HOLD_UNTIL,
    ]);
    Carbon::setTestNow(Carbon::parse('2038-01-02 00:00:00', 'UTC'));

    $result = app(DurableOutbox::class)->drain();

    expect($result)->toBeInstanceOf(DrainResult::class)
        ->and(DB::table('swarm_durable_outbox')->where('id', $id)->exists())->toBeFalse();
});

test('a released direct row counts once as a first direct reclaim, and a later failed-relay reclaim does not', function (): void {
    config()->set('swarm.durable.eager_dispatch', true);
    config()->set('swarm.durable.relay.reservation_timeout_seconds', 60);
    capSet(1);
    $runId = capStartFanOut(2, relay: false);
    Carbon::setTestNow(now('UTC')->addHours(2));

    // w0 ran and settled its own row; only the released w1 row is left.
    DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', 'parallel_node:w0')->update(['status' => 'completed']);
    DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('available_at', '!=', DatabaseDurableOutbox::HOLD_UNTIL)->delete();
    app(DurableBranchAdmission::class)->reconcile($runId);
    $released = DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'branch')->orderByDesc('id')->first();
    expect((string) $released->reserved_at)->toBe((string) $released->available_at);

    // Its push is lost; the reservation goes stale.
    Carbon::setTestNow(now('UTC')->addMinutes(5));
    app()->bind(DurableJobDispatcher::class, fn () => tap(Mockery::mock(DurableJobDispatcher::class), function ($mock): void {
        $mock->shouldReceive('forDrain')->andReturnSelf();
        $mock->shouldReceive('dispatchBranch')->andThrow(new RuntimeException('queue down'));
        $mock->shouldReceive('dispatchStep');
    }));
    app()->forgetInstance(DurableOutbox::class);

    $first = app(DurableOutbox::class)->drain();
    Carbon::setTestNow(now('UTC')->addMinutes(5));
    $second = app(DurableOutbox::class)->drain();

    expect($first->reclaimedDirect)->toBe(1)
        ->and($second->reclaimedDirect)->toBe(0)
        ->and($second->reclaimed)->toBeGreaterThanOrEqual(1);
});

// ---------------------------------------------------------------------------
// Operator signals
// ---------------------------------------------------------------------------

test('swarm:health does not report held rows as stuck relay work and shows them on their own line', function (): void {
    capSet(4);
    $runId = capStartFanOut(16);
    app(DurableOutbox::class)->drain();
    DB::table('swarm_durable_outbox')->where('run_id', $runId)->update(['created_at' => now('UTC')->subHour()]);

    Artisan::call('swarm:health', ['--durable' => true, '--json' => true]);
    $checks = collect(json_decode(Artisan::output(), true)['checks']);

    expect($checks->firstWhere('component', 'Outbox relay')['status'])->toBe('ok')
        ->and($checks->firstWhere('component', 'Held branches')['details'])->toContain('12 branch(es) held across 1 run(s)');
});

test('swarm:health shows no held branches when the cap is off', function (): void {
    Artisan::call('swarm:health', ['--durable' => true, '--json' => true]);
    $checks = collect(json_decode(Artisan::output(), true)['checks']);

    expect($checks->firstWhere('component', 'Held branches')['details'])->toBe('none held by the fan-out cap');
});

// ---------------------------------------------------------------------------
// Configuration fails loudly at run start
// ---------------------------------------------------------------------------

test('an invalid cap throws when a durable run starts and creates no run', function (mixed $value): void {
    capSet($value);
    capPlan(4);

    expect(fn () => FakeWideHierarchicalSwarm::make()->dispatchDurable('cap-task'))
        ->toThrow(SwarmException::class, 'max_concurrent_branches');
    expect(DB::table('swarm_durable_runs')->count())->toBe(0);
})->with([0, -1, 'abc', '1.5', 1.5, '4x']);

test('an invalid cap also throws for a sequential durable run', function (): void {
    capSet(0);

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable('task'))->toThrow(SwarmException::class);
});

test('a numeric string cap is accepted', function (): void {
    capSet('4');
    $runId = capStartFanOut(6);

    expect(capHeldRows($runId))->toHaveCount(2);
});

test('a cap with a custom outbox is rejected at run start', function (): void {
    capSet(4);
    app()->bind(DurableOutbox::class, fn () => Mockery::mock(DurableOutbox::class)->shouldIgnoreMissing());
    app()->forgetInstance(DurableSwarmManager::class);
    app()->forgetInstance(SwarmRunner::class);
    capPlan(4);

    expect(fn () => FakeWideHierarchicalSwarm::make()->dispatchDurable('cap-task'))
        ->toThrow(SwarmException::class, 'bundled database durable run store');
    expect(DB::table('swarm_durable_runs')->count())->toBe(0);
});

test('a cap with the bundled outbox on another connection instance of the same name is rejected', function (): void {
    capSet(4);
    $other = app('db.factory')->make(config('database.connections.testing'), 'testing');
    app()->singleton(DurableOutbox::class, fn () => new DatabaseDurableOutbox($other, app(ConfigRepository::class), app(DurableJobDispatcher::class)));
    app()->forgetInstance(DurableSwarmManager::class);
    app()->forgetInstance(SwarmRunner::class);
    capPlan(4);

    expect(fn () => FakeWideHierarchicalSwarm::make()->dispatchDurable('cap-task'))
        ->toThrow(SwarmException::class, 'same database connection');
});

test('a cap that becomes unsupported mid-run is rejected before the fan-out writes anything', function (): void {
    capSet(null);
    capPlan(6);
    $runId = FakeWideHierarchicalSwarm::make()->dispatchDurable('cap-task')->runId;
    // Step 0 runs the coordinator; the next step is the fan-out.
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    $nextStep = (int) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('next_step_index');

    capSet(4);
    $other = app('db.factory')->make(config('database.connections.testing'), 'testing');
    app()->singleton(DurableOutbox::class, fn () => new DatabaseDurableOutbox($other, app(ConfigRepository::class), app(DurableJobDispatcher::class)));
    app()->forgetInstance(DurableSwarmManager::class);

    expect(fn () => (new AdvanceDurableSwarm($runId, $nextStep))->handle(app(DurableSwarmManager::class)))
        ->toThrow(SwarmException::class, 'same database connection');
    expect(DB::table('swarm_durable_branches')->where('run_id', $runId)->count())->toBe(0)
        ->and(DB::table('swarm_durable_outbox')->where('run_id', $runId)->where('dispatch_type', 'branch')->count())->toBe(0);
});

test('a release whose eager push is lost is delivered by the relay, and a late duplicate push is a no-op', function (): void {
    config()->set('swarm.durable.eager_dispatch', true);
    capSet(1);
    $runId = capStartFanOut(2, relay: false);

    (new AdvanceDurableBranch($runId, 'parallel_node:w0'))->handle(app(DurableSwarmManager::class));

    $branchJobs = fn (?bool $eager): array => array_values(array_filter(
        capPendingJobs([]),
        fn (object $job): bool => $job instanceof AdvanceDurableBranch && $job->branchId === 'parallel_node:w1'
            && ($eager === null || ($job->outboxEntryId !== null) === $eager),
    ));
    // The release was pushed eagerly; treat that push as lost for now.
    $lost = $branchJobs(true);
    expect($lost)->toHaveCount(1)
        ->and(capRowExists($lost[0]->outboxEntryId))->toBeTrue();

    Carbon::setTestNow(now('UTC')->addMinutes(5));
    app(DurableOutbox::class)->drain();

    $relayed = $branchJobs(false);
    expect($relayed)->toHaveCount(1);

    $relayed[0]->handle(app(DurableSwarmManager::class));
    $lost[0]->handle(app(DurableSwarmManager::class));

    expect(array_count_values(CapProbe::$starts)['parallel_node:w1'] ?? 0)->toBe(1)
        ->and(capBranchStatuses($runId)['parallel_node:w1'])->toBe('completed');
});

test('a release that fails part-way rolls back: every row it touched is still held', function (): void {
    app()->singleton(DurableOutbox::class, CapRollbackOutbox::class);
    app()->forgetInstance(DurableSwarmManager::class);
    capSet(1);
    $runId = capStartFanOut(4);
    DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', 'parallel_node:w0')->update(['status' => 'completed']);

    app(DurableSwarmManager::class)->recover();

    expect(capHeldRows($runId))->toHaveCount(3)
        ->and(capAdmittedUnfinished($runId))->toBe(0);
});
