<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmOperator;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Process-concurrency coverage for the PUBLIC operator control contract (#329).
 *
 * The promoted SwarmOperator::cancel() verb runs through the thin adapter →
 * DurableSwarmManager → DurableLifecycleController, which wraps the store
 * mutation in connection->transaction() and a main-row status guard. The
 * design-gate invariant this test defends: a control verb issued during an
 * in-flight advance either applies its transition (or throws) — it never
 * corrupts the run into a torn state.
 *
 * This complements DurableRunStateConcurrencyTest, which races the store's
 * cancel() directly. Here the race goes through the whole promoted control
 * surface, proving the transaction + status guard survive the adapter promotion.
 *
 * Skips cleanly on connections that cannot be shared across child processes
 * (in-memory SQLite) — mirroring the sibling concurrency tests.
 */
pest()->group('process-concurrency', 'skip-locked-real-db');

function swarmOperatorControlConcurrencyDriverSupported(): bool
{
    return ! in_array(
        DB::connection()->getDriverName(),
        ['sqlite', 'sqlsrv'],
        true,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function swarmOperatorControlRunRow(string $runId, Carbon $now, array $overrides = []): array
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
 * Worker that acquires the lease and advances (markFailed) each run — the
 * lease-guarded in-flight advance that the operator cancel races.
 *
 * @param  array<int, string>  $runIds
 */
function swarmOperatorControlAdvanceWorker(array $runIds): Closure
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
                $outcomes[$runId] = 'aborted:'.$e::class;
            }
        }

        return $outcomes;
    };
}

/**
 * Worker that cancels each run THROUGH THE PUBLIC SwarmOperator contract.
 *
 * @param  array<int, string>  $runIds
 */
function swarmOperatorControlCancelWorker(array $runIds): Closure
{
    return static function () use ($runIds): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $operator = app(SwarmOperator::class);
        $outcomes = [];

        foreach ($runIds as $runId) {
            try {
                $outcomes[$runId] = $operator->cancel($runId)->status;
            } catch (SwarmException $e) {
                // Fail-loud: the run was already terminal (advance won) or the
                // control verb lost the row contention and rolled back. Both are
                // legal "operator lost" outcomes — never a torn state.
                $outcomes[$runId] = 'threw:SwarmException';
            } catch (Throwable $e) {
                // The engine aborted this transaction as the deadlock victim.
                $outcomes[$runId] = 'aborted:'.$e::class;
            }
        }

        return $outcomes;
    };
}

beforeEach(function (): void {
    if (! swarmOperatorControlConcurrencyDriverSupported()) {
        $this->markTestSkipped(
            'SwarmOperator control concurrency test requires a shared database engine '
            .'reachable from child processes (mysql/pgsql). Current driver: '
            .DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');

    DB::table('swarm_durable_node_states')->delete();
    DB::table('swarm_durable_run_state')->delete();
    DB::table('swarm_durable_runs')->delete();
});

test('operator cancel racing a lease-holding advance either transitions or throws — never corrupts', function (): void {
    /** @var ConcurrencyManager $concurrency */
    $concurrency = $this->app->make(ConcurrencyManager::class);

    $now = now('UTC');
    $runCount = 24;
    $runIds = [];

    for ($i = 0; $i < $runCount; $i++) {
        $runId = 'operator-control-race-'.$i;
        $runIds[] = $runId;

        DB::table('swarm_durable_runs')->insert(swarmOperatorControlRunRow($runId, $now));
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
        swarmOperatorControlAdvanceWorker($runIds),
        swarmOperatorControlCancelWorker($runIds),
    ]);

    expect($results)->toHaveCount(2);

    // A run is torn if its (status, failure) pair matches neither legal serial
    // outcome:
    //   - cancel-wins:  status = cancelled AND failure IS NULL.
    //   - advance-wins: status = failed AND failure = the advance sentinel.
    // Any other pair means the operator cancel and the advance interleaved their
    // read-modify-write and lost an update — the exact corruption the
    // transaction + main-row status guard must prevent, adapter promotion or not.
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

    expect($torn)->toBe([]);
});
