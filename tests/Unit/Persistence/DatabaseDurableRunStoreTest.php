<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

test('durable run store find merges side tables into legacy inspection shape', function () {
    $runId = 'unit-durable-merge-1';
    $now = now('UTC');

    DB::table('swarm_durable_runs')->insert([
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
    ]);

    DB::table('swarm_durable_run_state')->insert([
        'run_id' => $runId,
        'route_plan' => json_encode(['start_at' => 'writer_node', 'nodes' => []]),
        'route_plan_projected' => false,
        'failure' => json_encode(['message' => 'boom', 'class' => RuntimeException::class]),
        'retry_policy' => json_encode(['max' => 3]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('swarm_durable_node_states')->insert([
        'run_id' => $runId,
        'node_id' => 'step:0',
        'state' => json_encode(['node_id' => 'step:0', 'status' => 'leased']),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $store = app(DatabaseDurableRunStore::class);
    $run = $store->find($runId);

    expect($run)->not->toBeNull()
        ->and($run['route_plan']['start_at'])->toBe('writer_node')
        ->and($run['failure']['message'])->toBe('boom')
        ->and($run['retry_policy']['max'])->toBe(3)
        ->and($run['node_states']['step:0']['status'])->toBe('leased');
});

/**
 * Seed a durable run row whose column defaults suit the recovery sweeps.
 *
 * @param  array<string, mixed>  $overrides
 */
function seedDurableRun(string $runId, array $overrides = []): void
{
    $now = now('UTC');

    DB::table('swarm_durable_runs')->insert(array_merge([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'execution_mode' => 'durable',
        'coordination_profile' => 'step_durable',
        'status' => 'running',
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
        // Six minutes ago so the run sits past the default 300s recovery grace.
        'created_at' => $now->copy()->subSeconds(360),
        'updated_at' => $now->copy()->subSeconds(360),
    ], $overrides));
}

/**
 * Capture the legacy find()-per-record output for a sweep so the batch-loaded
 * sweep can be deep-compared against it.
 *
 * @param  array<int, array<string, mixed>>  $sweepResult
 * @return array<int, array<string, mixed>>
 */
function legacyFindPerRecord(DatabaseDurableRunStore $store, array $sweepResult): array
{
    return array_map(
        fn (array $run): array => $store->find($run['run_id']),
        $sweepResult,
    );
}

test('recoverable batch-loads side tables and is byte-for-byte equal to find() per record', function () {
    seedDurableRun('rec-a');
    seedDurableRun('rec-b');
    seedDurableRun('rec-c');

    $now = now('UTC');

    // rec-a: run_state present + duplicated node_id (highest id must win).
    DB::table('swarm_durable_run_state')->insert([
        'run_id' => 'rec-a',
        'route_plan' => json_encode(['start_at' => 'writer_node', 'nodes' => []]),
        'route_plan_projected' => false,
        'failure' => null,
        'retry_policy' => json_encode(['max' => 3]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    // step:0 is written twice (an upsert-style re-write): the (run_id, node_id)
    // unique constraint collapses it to a single row, so last-write-wins is exactly
    // what both loadNodeStatesMap() and the batch path must reproduce. step:1 is
    // inserted with a LOWER id than step:0's final update to prove map order tracks
    // id ordering, not insertion recency.
    DB::table('swarm_durable_node_states')->insert([
        ['run_id' => 'rec-a', 'node_id' => 'step:0', 'state' => json_encode(['status' => 'first']), 'created_at' => $now, 'updated_at' => $now],
        ['run_id' => 'rec-a', 'node_id' => 'step:1', 'state' => json_encode(['status' => 'pending']), 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('swarm_durable_node_states')
        ->where('run_id', 'rec-a')->where('node_id', 'step:0')
        ->update(['state' => json_encode(['status' => 'last']), 'updated_at' => $now]);

    // rec-b: no run_state row at all (null route_plan/failure/retry_policy).
    DB::table('swarm_durable_node_states')->insert([
        'run_id' => 'rec-b', 'node_id' => 'step:0', 'state' => json_encode(['status' => 'solo']), 'created_at' => $now, 'updated_at' => $now,
    ]);

    // rec-c: run_state present, no node_states.
    DB::table('swarm_durable_run_state')->insert([
        'run_id' => 'rec-c',
        'route_plan' => null,
        'route_plan_projected' => false,
        'failure' => json_encode(['message' => 'boom']),
        'retry_policy' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $store = app(DatabaseDurableRunStore::class);
    $result = $store->recoverable();

    expect($result)->toEqual(legacyFindPerRecord($store, $result));

    // Duplicated node_id resolves to the highest-id row (last write wins).
    $recA = collect($result)->firstWhere('run_id', 'rec-a');
    expect($recA['node_states']['step:0']['status'])->toBe('last')
        ->and($recA['node_states']['step:1']['status'])->toBe('pending');

    // Missing run_state → null side-table fields, as find() produces.
    $recB = collect($result)->firstWhere('run_id', 'rec-b');
    expect($recB['route_plan'])->toBeNull()
        ->and($recB['failure'])->toBeNull()
        ->and($recB['retry_policy'])->toBeNull()
        ->and($recB['node_states']['step:0']['status'])->toBe('solo');
});

test('recoverable issues a constant 3 queries regardless of candidate count', function () {
    $store = app(DatabaseDurableRunStore::class);

    foreach (range(1, 6) as $i) {
        seedDurableRun("count-$i");
        DB::table('swarm_durable_run_state')->insert([
            'run_id' => "count-$i", 'route_plan' => null, 'route_plan_projected' => false,
            'failure' => null, 'retry_policy' => null, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('swarm_durable_node_states')->insert([
            'run_id' => "count-$i", 'node_id' => 'step:0', 'state' => json_encode(['s' => $i]),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $result = $store->recoverable();

    expect($result)->toHaveCount(6)
        ->and($queries)->toBe(3);
});

test('recoverable issues zero side-table queries when there are no candidates', function () {
    $store = app(DatabaseDurableRunStore::class);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect($store->recoverable())->toBe([])
        ->and($queries)->toBe(1);
});

test('dueRetries issues a constant 3 queries and equals find() per record', function () {
    $store = app(DatabaseDurableRunStore::class);
    $now = now('UTC');

    foreach (range(1, 5) as $i) {
        seedDurableRun("retry-$i", [
            'status' => 'pending',
            'next_retry_at' => $now->copy()->subMinute(),
        ]);
        DB::table('swarm_durable_node_states')->insert([
            'run_id' => "retry-$i", 'node_id' => 'step:0', 'state' => json_encode(['s' => $i]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $result = $store->dueRetries();

    expect($result)->toHaveCount(5)
        ->and($queries)->toBe(3)
        ->and($result)->toEqual(legacyFindPerRecord($store, $result));
});

test('recoverableWaitingJoins batch-loads branches, preserves terminal filtering, and is find()-equal', function () {
    $store = app(DatabaseDurableRunStore::class);
    $now = now('UTC');
    $stale = $now->copy()->subSeconds(360);

    // wj-keep: all branches under the waiting (current) node are terminal → kept.
    seedDurableRun('wj-keep', ['status' => 'waiting', 'current_node_id' => 'join:0', 'updated_at' => $stale]);
    // wj-drop: a branch under the current node is still running → filtered out.
    seedDurableRun('wj-drop', ['status' => 'waiting', 'current_node_id' => 'join:0', 'updated_at' => $stale]);
    // wj-other-parent: terminal branches under the current node, plus a running
    // branch under a DIFFERENT parent node — must be kept (do not collapse parents).
    seedDurableRun('wj-other', ['status' => 'waiting', 'current_node_id' => 'join:0', 'updated_at' => $stale]);

    $branchRow = function (string $runId, string $branchId, string $parent, string $status) use ($now): array {
        return [
            'run_id' => $runId, 'branch_id' => $branchId, 'step_index' => 0, 'node_id' => 'n',
            'agent_class' => 'A', 'parent_node_id' => $parent, 'status' => $status,
            'input' => 'x', 'created_at' => $now, 'updated_at' => $now,
        ];
    };

    DB::table('swarm_durable_branches')->insert([
        $branchRow('wj-keep', 'b1', 'join:0', 'completed'),
        $branchRow('wj-keep', 'b2', 'join:0', 'failed'),
        $branchRow('wj-drop', 'b1', 'join:0', 'completed'),
        $branchRow('wj-drop', 'b2', 'join:0', 'running'),
        $branchRow('wj-other', 'b1', 'join:0', 'completed'),
        $branchRow('wj-other', 'b2', 'other:0', 'running'),
    ]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $result = $store->recoverableWaitingJoins();

    $keptIds = collect($result)->pluck('run_id')->all();

    expect($keptIds)->toContain('wj-keep')
        ->and($keptIds)->toContain('wj-other')
        ->and($keptIds)->not->toContain('wj-drop')
        ->and($queries)->toBe(4)
        ->and($result)->toEqual(legacyFindPerRecord($store, $result));
});

test('recoverableWaitingJoins query count is constant across candidate count', function () {
    $store = app(DatabaseDurableRunStore::class);
    $now = now('UTC');
    $stale = $now->copy()->subSeconds(360);

    foreach (range(1, 5) as $i) {
        seedDurableRun("wjc-$i", ['status' => 'waiting', 'current_node_id' => 'join:0', 'updated_at' => $stale]);
        DB::table('swarm_durable_branches')->insert([
            'run_id' => "wjc-$i", 'branch_id' => 'b1', 'step_index' => 0, 'node_id' => 'n',
            'agent_class' => 'A', 'parent_node_id' => 'join:0', 'status' => 'completed',
            'input' => 'x', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $result = $store->recoverableWaitingJoins();

    expect($result)->toHaveCount(5)
        ->and($queries)->toBe(4);
});
