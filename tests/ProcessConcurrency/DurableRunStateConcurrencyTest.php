<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Process-concurrency coverage for the read-modify-write inside
 * DatabaseDurableRunStore::upsertRunState() on the swarm_durable_run_state row
 * (issue #273).
 *
 * upsertRunState() reads the run_state row with first(), merges the patch in
 * PHP, and writes with updateOrInsert() — no lockForUpdate() or surrounding
 * row lock. The question the issue asks is REACHABILITY: can two writers ever
 * touch one run's run_state row concurrently and lose an update?
 *
 * Per the #273 design gate (.claude/design-state.json), a test of *two advance
 * writers* proves nothing — every advance caller (markFailed/markTerminal,
 * releaseWaitingRunForJoin, scheduleRetry) runs inside connection->transaction()
 * paired with guardedUpdate(), and acquireLease() is an atomic conditional
 * update, so only one execution_token is ever valid per run. Two advancers are
 * already lease-serialized and would pass without a lost update — a false green.
 *
 * The only upsertRunState() caller NOT gated by the execution_token is cancel()
 * (:994): it flips status pending|paused|waiting -> cancelled, nulls the lease,
 * then read-modify-writes run_state. So the genuinely-unguarded interleavings
 * are:
 *
 *   - Scenario 1 (cancel-vs-advance): cancel() races a lease-holding markFailed()
 *     on the same run. The invariant: the terminal run_state is internally
 *     consistent with exactly one winner — never a torn merge.
 *   - Scenario 2 (lease-expiry overlap): a stale worker whose lease expired
 *     calls markFailed() with its now-invalid token while a second worker
 *     re-acquires the lease and advances. The stale worker's write must never
 *     land.
 *
 * Both run two workers through the Laravel process concurrency driver against a
 * real shared database. They skip cleanly on connections that cannot be shared
 * across child processes (in-memory SQLite) — mirroring AuditOutboxConcurrencyTest.
 */
// Tagged `skip-locked-real-db` so the CI lane (`test:process-concurrency:ci`)
// excludes it under `--fail-on-skipped`: it requires a shared MySQL/Postgres
// connection and skips on the default in-memory SQLite, which is per-process and
// cannot be reached by the child processes the concurrency driver spawns.
pest()->group('process-concurrency', 'skip-locked-real-db');

/**
 * True if the configured testing connection is a real, shared DB engine the
 * process concurrency driver can reach from child processes. SQLite (the
 * testbench default) is excluded: :memory: is per-process and a file SQLite has
 * no row-lock semantics worth racing. sqlsrv is excluded to mirror the audit
 * outbox lane.
 */
function durableRunStateConcurrencyDriverSupported(): bool
{
    return ! in_array(
        DB::connection()->getDriverName(),
        ['sqlite', 'sqlsrv'],
        true,
    );
}

/**
 * Build a full swarm_durable_runs row. Parent-process helper only (never
 * serialized to a child), so it may live as a free function here.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function durableRunStateRaceRunRow(string $runId, Carbon $now, array $overrides = []): array
{
    return array_merge([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'execution_mode' => 'durable',
        'coordination_profile' => 'step_durable',
        'status' => 'pending',
        'next_step_index' => 0,
        'current_step_index' => null,
        'total_steps' => 1,
        'route_cursor' => null,
        'route_start_node_id' => null,
        'current_node_id' => null,
        'completed_node_ids' => json_encode([]),
        'timeout_at' => $now->copy()->addHour(),
        'step_timeout_seconds' => 300,
        'attempts' => 0,
        'lease_acquired_at' => null,
        'execution_token' => null,
        'leased_until' => null,
        'recovery_count' => 0,
        'last_recovered_at' => null,
        'pause_requested_at' => null,
        'paused_at' => null,
        'resumed_at' => null,
        'cancel_requested_at' => null,
        'cancelled_at' => null,
        'timed_out_at' => null,
        'wait_reason' => null,
        'waiting_since' => null,
        'wait_timeout_at' => null,
        'last_progress_at' => null,
        'retry_attempt' => 0,
        'next_retry_at' => null,
        'parent_run_id' => null,
        'queue_connection' => null,
        'queue_name' => null,
        'finished_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);
}

/**
 * Worker closure that, for each run id in order, acquires the lease and calls
 * markFailed() with a per-run failure sentinel. This is the lease-guarded
 * *advance* writer that races cancel() in scenario 1.
 *
 * Built by a free function so its scope class is null and it serializes cleanly
 * to the child PHP process, which runs `php artisan invoke-serialized-closure`
 * against bare testbench (no Pest runtime, no this test file). Everything the
 * child needs is bootstrapped inline; only FQCN class references (resolved via
 * the composer autoloader) and framework globals are used. See
 * AuditOutboxConcurrencyTest::auditOutboxConcurrencyWorker for the full rationale.
 *
 * @param  array<int, string>  $runIds
 */
function durableRunStateAdvanceWorker(array $runIds): Closure
{
    return static function () use ($runIds): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $store = app(DurableRunStore::class);
        $outcomes = [];

        foreach ($runIds as $runId) {
            try {
                // acquireLease() is NOT atomic end to end: the atomic claim
                // UPDATE is followed by a second recordNodeState() transaction
                // that runs its own guardedUpdate(). A cancel() that nulls the
                // lease in that window makes acquireLease() itself throw — so the
                // whole acquire+advance sequence is inside the try.
                $token = $store->acquireLease($runId, 0, 300);

                if ($token === null) {
                    $outcomes[$runId] = 'no-lease';

                    continue;
                }

                $store->markFailed($runId, $token, [
                    'class' => RuntimeException::class,
                    'message' => 'advance-sentinel:'.$runId,
                ]);
                $outcomes[$runId] = 'failed';
            } catch (Throwable $e) {
                // The advancer lost the row contention with cancel(): either
                // cancel() nulled the lease first (LostDurableLeaseException) or
                // MySQL aborted this transaction as the deadlock victim
                // (QueryException). Both mean the advance rolled back without
                // committing a status=failed + failure write to run_state — a
                // legal "advancer lost" outcome, not a lost update. The
                // final-state assertion is the real gate.
                $outcomes[$runId] = 'aborted:'.$e::class;
            }
        }

        return $outcomes;
    };
}

/**
 * Worker closure that cancels each run in order. This is the unguarded writer
 * (cancel() is not gated by the execution_token) that races the advancer.
 *
 * @param  array<int, string>  $runIds
 */
function durableRunStateCancelWorker(array $runIds): Closure
{
    return static function () use ($runIds): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $store = app(DurableRunStore::class);
        $outcomes = [];

        foreach ($runIds as $runId) {
            try {
                $outcomes[$runId] = $store->cancel($runId) ? 'cancelled' : 'cancel-noop';
            } catch (Throwable $e) {
                // cancel() lost the row contention: MySQL aborted it as the
                // deadlock victim. It rolled back without touching run_state, so
                // the advancer wins — also a legal outcome. See the advance
                // worker's catch for the full rationale.
                $outcomes[$runId] = 'aborted:'.$e::class;
            }
        }

        return $outcomes;
    };
}

/**
 * Worker closure that calls markFailed() with a STALE (already-invalid) token,
 * simulating a worker whose lease expired mid-flight. Its guardedUpdate() must
 * find 0 rows and throw, so its write never lands.
 *
 * @param  array<int, string>  $runIds
 */
function durableRunStateStaleAdvanceWorker(array $runIds): Closure
{
    return static function () use ($runIds): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $store = app(DurableRunStore::class);
        $outcomes = [];

        foreach ($runIds as $runId) {
            try {
                $store->markFailed($runId, 'stale-token:'.$runId, [
                    'class' => RuntimeException::class,
                    'message' => 'stale-sentinel:'.$runId,
                ]);
                $outcomes[$runId] = 'failed';
            } catch (LostDurableLeaseException) {
                $outcomes[$runId] = 'lost-lease';
            }
        }

        return $outcomes;
    };
}

/**
 * Worker closure that re-acquires the (expired) lease and advances cleanly.
 * Its fresh failure sentinel is the only write that may legally survive.
 *
 * @param  array<int, string>  $runIds
 */
function durableRunStateReacquireWorker(array $runIds): Closure
{
    return static function () use ($runIds): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $store = app(DurableRunStore::class);
        $outcomes = [];

        foreach ($runIds as $runId) {
            $token = $store->acquireLease($runId, 0, 300);

            if ($token === null) {
                $outcomes[$runId] = 'no-lease';

                continue;
            }

            $store->markFailed($runId, $token, [
                'class' => RuntimeException::class,
                'message' => 'fresh-sentinel:'.$runId,
            ]);
            $outcomes[$runId] = 'failed';
        }

        return $outcomes;
    };
}

beforeEach(function (): void {
    if (! durableRunStateConcurrencyDriverSupported()) {
        $this->markTestSkipped(
            'Durable run_state concurrency test requires a shared database engine '
            .'reachable from child processes (mysql/pgsql). Current driver: '
            .DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');

    // Clear the three durable tables; delete (not truncate) and child-first to
    // avoid any FK ordering issues on a real engine.
    DB::table('swarm_durable_node_states')->delete();
    DB::table('swarm_durable_run_state')->delete();
    DB::table('swarm_durable_runs')->delete();
});

test('cancel racing a lease-holding advance never tears the run_state row', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    $now = now('UTC');
    $runCount = 24;
    $runIds = [];

    for ($i = 0; $i < $runCount; $i++) {
        $runId = 'run-state-race-'.$i;
        $runIds[] = $runId;

        DB::table('swarm_durable_runs')->insert(durableRunStateRaceRunRow($runId, $now));
        DB::table('swarm_durable_run_state')->insert([
            'run_id' => $runId,
            'route_plan' => json_encode(['start_at' => 'node:0', 'nodes' => []]),
            'route_plan_projected' => false,
            'failure' => null,
            'retry_policy' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // Both workers walk the SAME ordered run-id list, started concurrently, so
    // for each run a cancel() and a markFailed() race head-on.
    $results = $concurrency->driver('process')->run([
        durableRunStateAdvanceWorker($runIds),
        durableRunStateCancelWorker($runIds),
    ]);

    expect($results)->toHaveCount(2);

    // Note: this multi-process test does NOT assert that contention occurred on
    // any given run — the process driver gives no interleaving guarantee, and
    // empirically one worker sometimes completes its whole 24-run loop before
    // the other starts (a perfectly legal, contention-free pass). The guarantee
    // that the cancel-vs-advance serialization guard actually closes the
    // lost-update window is proven deterministically, independent of timing, in
    // tests/Unit/Persistence/DatabaseDurableRunStoreTest.php. This test's job is
    // the complementary one: under genuine multi-process load on a real engine,
    // no run ever lands in a torn state.

    // A run_state row is "torn" if its (status, failure) pair matches neither
    // legal serial outcome:
    //   - cancel-wins:  status = cancelled AND failure IS NULL (the advancer
    //                   rolled back via LostDurableLease, or never leased).
    //   - advance-wins: status = failed AND failure = the advance sentinel
    //                   (cancel no-opped because the run was already terminal).
    // Any other pair — e.g. cancelled-with-a-failure, or failed-with-null-failure
    // — means cancel() and markFailed() interleaved their read-modify-write on
    // run_state and lost an update.
    $torn = [];

    foreach ($runIds as $runId) {
        $run = DB::table('swarm_durable_runs')->where('run_id', $runId)->first();
        $state = DB::table('swarm_durable_run_state')->where('run_id', $runId)->first();

        $status = $run?->status;
        $failure = ($state?->failure === null) ? null : json_decode((string) $state->failure, true);

        $cancelWin = $status === 'cancelled' && $failure === null;
        $advanceWin = $status === 'failed'
            && is_array($failure)
            && ($failure['message'] ?? null) === 'advance-sentinel:'.$runId;

        if (! ($cancelWin xor $advanceWin)) {
            $torn[$runId] = ['status' => $status, 'failure' => $failure];
        }
    }

    // Empty => REFUTES reachability: the main-run-row lease/status write-lock
    // serializes the two writers before they both reach run_state. Non-empty =>
    // CONFIRMS a lost update and the fix is the lockForUpdate() guard inside
    // upsertRunState(). Either way the investigation (AC #1) is answered; the
    // dumped torn rows are the evidence to record in the PR.
    expect($torn)->toBe([]);
});

test('a stale lease holder never overwrites a re-acquired advance on run_state', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    $now = now('UTC');
    $runCount = 24;
    $runIds = [];

    for ($i = 0; $i < $runCount; $i++) {
        $runId = 'run-state-expiry-'.$i;
        $runIds[] = $runId;

        // Seed as a running run whose lease has already expired and whose token
        // is the one the stale worker will present.
        DB::table('swarm_durable_runs')->insert(durableRunStateRaceRunRow($runId, $now, [
            'status' => 'running',
            'execution_token' => 'stale-token:'.$runId,
            'lease_acquired_at' => $now->copy()->subMinutes(10),
            'leased_until' => $now->copy()->subMinutes(5),
        ]));
        DB::table('swarm_durable_run_state')->insert([
            'run_id' => $runId,
            'route_plan' => json_encode(['start_at' => 'node:0', 'nodes' => []]),
            'route_plan_projected' => false,
            'failure' => null,
            'retry_policy' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $results = $concurrency->driver('process')->run([
        durableRunStateStaleAdvanceWorker($runIds),
        durableRunStateReacquireWorker($runIds),
    ]);

    expect($results)->toHaveCount(2);

    // Non-vacuity guard: positively prove the stale worker actually attempted
    // and was rejected on every run — its guardedUpdate() must throw, so its
    // outcome is uniformly 'lost-lease'. Without this a silent stale-worker
    // no-op (e.g. markFailed() short-circuiting) would let the clobber check
    // below pass for the wrong reason.
    expect($results[0])->each->toBe('lost-lease');

    // The stale worker's guardedUpdate() can never match (expired lease / token
    // replaced on re-acquire), so the only failure that may land is the fresh
    // one. A stale sentinel in run_state — or a null failure on a failed run —
    // means the stale write clobbered the re-acquired advance.
    $clobbered = [];

    foreach ($runIds as $runId) {
        $run = DB::table('swarm_durable_runs')->where('run_id', $runId)->first();
        $state = DB::table('swarm_durable_run_state')->where('run_id', $runId)->first();

        $failure = ($state?->failure === null) ? null : json_decode((string) $state->failure, true);
        $message = is_array($failure) ? ($failure['message'] ?? null) : null;

        $clean = $run?->status === 'failed' && $message === 'fresh-sentinel:'.$runId;

        if (! $clean) {
            $clobbered[$runId] = ['status' => $run?->status, 'failure' => $failure];
        }
    }

    expect($clobbered)->toBe([]);
});
