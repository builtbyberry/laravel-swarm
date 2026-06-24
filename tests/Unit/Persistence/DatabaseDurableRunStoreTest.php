<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
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

test('recoverableWaitingJoins uses strict string match for parent_node_id — loosely-equal but distinct ids do not collide', function () {
    // Regression for F5: the preloaded-branch filter used loose (==) comparison.
    // "0" == "0.0" in PHP, so a run whose current_node_id is "0.0" could incorrectly
    // absorb branches filed under parent "0", and vice-versa. The fix uses === so
    // only an exact-string match gates the terminal check.

    $store = app(DatabaseDurableRunStore::class);
    $now = now('UTC');
    $stale = $now->copy()->subSeconds(360);

    // "strict-exact": current_node_id="0.0" — all branches under "0.0" are terminal.
    // Must be KEPT: its own branches are all done.
    seedDurableRun('strict-exact', ['status' => 'waiting', 'current_node_id' => '0.0', 'updated_at' => $stale]);

    // "strict-sibling": current_node_id="0" — its own branch is still running.
    // Must be DROPPED: the in-progress branch under "0" is not terminal.
    // Without strict matching the "strict-exact" run would absorb "0" branches (since "0" == "0.0")
    // and "strict-sibling" might look terminal when it should not; the inverse is also true.
    seedDurableRun('strict-sibling', ['status' => 'waiting', 'current_node_id' => '0', 'updated_at' => $stale]);

    $branchRow = function (string $runId, string $branchId, string $parent, string $status) use ($now): array {
        return [
            'run_id' => $runId, 'branch_id' => $branchId, 'step_index' => 0, 'node_id' => 'n',
            'agent_class' => 'A', 'parent_node_id' => $parent, 'status' => $status,
            'input' => 'x', 'created_at' => $now, 'updated_at' => $now,
        ];
    };

    DB::table('swarm_durable_branches')->insert([
        // "strict-exact" run: branches under "0.0" (exact match) — all terminal.
        $branchRow('strict-exact', 'se-b1', '0.0', 'completed'),
        $branchRow('strict-exact', 'se-b2', '0.0', 'failed'),
        // "strict-sibling" run: branch under "0" (sibling id) — still running.
        $branchRow('strict-sibling', 'ss-b1', '0', 'running'),
    ]);

    $result = $store->recoverableWaitingJoins();
    $keptIds = collect($result)->pluck('run_id')->all();

    // "strict-exact" has all terminal branches under its exact parent_node_id "0.0".
    expect($keptIds)->toContain('strict-exact')
        // "strict-sibling" has a running branch under "0" — must NOT leak into "strict-exact"
        // and must NOT be masked by "strict-exact"'s terminal branches.
        ->and($keptIds)->not->toContain('strict-sibling');
});

test('recoverableWaitTimeouts batch-loads side tables and is byte-for-byte equal to find() per record', function () {
    $store = app(DatabaseDurableRunStore::class);
    $now = now('UTC');
    // A wait_timeout_at in the past makes the run a recovery candidate.
    $expired = $now->copy()->subSeconds(60);

    $seedWait = fn (string $runId) => seedDurableRun($runId, [
        'status' => 'waiting',
        'wait_timeout_at' => $expired,
    ]);

    // wt-a: run_state present + duplicated node_id (highest id must win).
    $seedWait('wt-a');
    DB::table('swarm_durable_run_state')->insert([
        'run_id' => 'wt-a',
        'route_plan' => json_encode(['start_at' => 'writer_node', 'nodes' => []]),
        'route_plan_projected' => false,
        'failure' => null,
        'retry_policy' => json_encode(['max' => 3]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('swarm_durable_node_states')->insert([
        ['run_id' => 'wt-a', 'node_id' => 'step:0', 'state' => json_encode(['status' => 'first']), 'created_at' => $now, 'updated_at' => $now],
        ['run_id' => 'wt-a', 'node_id' => 'step:1', 'state' => json_encode(['status' => 'pending']), 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('swarm_durable_node_states')
        ->where('run_id', 'wt-a')->where('node_id', 'step:0')
        ->update(['state' => json_encode(['status' => 'last']), 'updated_at' => $now]);

    // wt-b: no run_state row at all (null side-table fields).
    $seedWait('wt-b');
    DB::table('swarm_durable_node_states')->insert([
        'run_id' => 'wt-b', 'node_id' => 'step:0', 'state' => json_encode(['status' => 'solo']), 'created_at' => $now, 'updated_at' => $now,
    ]);

    // wt-c: run_state present, no node_states.
    $seedWait('wt-c');
    DB::table('swarm_durable_run_state')->insert([
        'run_id' => 'wt-c',
        'route_plan' => null,
        'route_plan_projected' => false,
        'failure' => json_encode(['message' => 'boom']),
        'retry_policy' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $result = $store->recoverableWaitTimeouts();

    expect($result)->toHaveCount(3)
        ->and($result)->toEqual(legacyFindPerRecord($store, $result));

    // Duplicated node_id resolves to the highest-id row (last write wins).
    $recA = collect($result)->firstWhere('run_id', 'wt-a');
    expect($recA['node_states']['step:0']['status'])->toBe('last')
        ->and($recA['node_states']['step:1']['status'])->toBe('pending');

    // Missing run_state → null side-table fields, as find() produces.
    $recB = collect($result)->firstWhere('run_id', 'wt-b');
    expect($recB['route_plan'])->toBeNull()
        ->and($recB['failure'])->toBeNull()
        ->and($recB['retry_policy'])->toBeNull()
        ->and($recB['node_states']['step:0']['status'])->toBe('solo');
});

test('recoverableWaitTimeouts issues a constant 3 queries regardless of candidate count', function () {
    $store = app(DatabaseDurableRunStore::class);
    $now = now('UTC');
    $expired = $now->copy()->subSeconds(60);

    foreach (range(1, 6) as $i) {
        seedDurableRun("wt-count-$i", ['status' => 'waiting', 'wait_timeout_at' => $expired]);
        DB::table('swarm_durable_run_state')->insert([
            'run_id' => "wt-count-$i", 'route_plan' => null, 'route_plan_projected' => false,
            'failure' => null, 'retry_policy' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('swarm_durable_node_states')->insert([
            'run_id' => "wt-count-$i", 'node_id' => 'step:0', 'state' => json_encode(['s' => $i]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $result = $store->recoverableWaitTimeouts();

    expect($result)->toHaveCount(6)
        ->and($queries)->toBe(3);
});

test('recoverableWaitTimeouts issues a single query when there are no candidates', function () {
    $store = app(DatabaseDurableRunStore::class);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect($store->recoverableWaitTimeouts())->toBe([])
        ->and($queries)->toBe(1);
});

/**
 * Seed a terminal child run linked to a waiting parent. The parent_run_id FK
 * requires the parent durable run to exist first.
 */
function seedTerminalChild(string $parentRunId, string $childRunId, string $status = 'completed'): void
{
    $now = now('UTC');

    DB::table('swarm_durable_child_runs')->insert([
        'parent_run_id' => $parentRunId,
        'child_run_id' => $childRunId,
        'child_swarm_class' => 'ChildSwarm',
        'wait_name' => 'children',
        'context_payload' => json_encode([]),
        'status' => $status,
        'output' => null,
        'failure' => null,
        'dispatched_at' => $now->copy()->subMinute(),
        'terminal_event_dispatched_at' => null,
        'created_at' => $now->copy()->subMinute(),
        'updated_at' => $now->copy()->subMinute(),
    ]);
}

test('parentsWaitingOnTerminalChildren batch-loads side tables and is byte-for-byte equal to find() per record', function () {
    $store = app(DatabaseDurableRunStore::class);
    $now = now('UTC');

    // pw-a: run_state present + duplicated node_id (highest id must win).
    seedDurableRun('pw-a', ['status' => 'waiting']);
    seedTerminalChild('pw-a', 'pw-a-child-1', 'completed');
    DB::table('swarm_durable_run_state')->insert([
        'run_id' => 'pw-a',
        'route_plan' => json_encode(['start_at' => 'writer_node', 'nodes' => []]),
        'route_plan_projected' => false,
        'failure' => null,
        'retry_policy' => json_encode(['max' => 3]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('swarm_durable_node_states')->insert([
        ['run_id' => 'pw-a', 'node_id' => 'step:0', 'state' => json_encode(['status' => 'first']), 'created_at' => $now, 'updated_at' => $now],
        ['run_id' => 'pw-a', 'node_id' => 'step:1', 'state' => json_encode(['status' => 'pending']), 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('swarm_durable_node_states')
        ->where('run_id', 'pw-a')->where('node_id', 'step:0')
        ->update(['state' => json_encode(['status' => 'last']), 'updated_at' => $now]);

    // pw-b: no run_state row at all (null side-table fields).
    seedDurableRun('pw-b', ['status' => 'waiting']);
    seedTerminalChild('pw-b', 'pw-b-child-1', 'failed');
    DB::table('swarm_durable_node_states')->insert([
        'run_id' => 'pw-b', 'node_id' => 'step:0', 'state' => json_encode(['status' => 'solo']), 'created_at' => $now, 'updated_at' => $now,
    ]);

    $result = $store->parentsWaitingOnTerminalChildren();

    expect($result)->toHaveCount(2)
        ->and($result)->toEqual(legacyFindPerRecord($store, $result));

    // Duplicated node_id resolves to the highest-id row (last write wins).
    $recA = collect($result)->firstWhere('run_id', 'pw-a');
    expect($recA['node_states']['step:0']['status'])->toBe('last')
        ->and($recA['node_states']['step:1']['status'])->toBe('pending');

    // Missing run_state → null side-table fields, as find() produces.
    $recB = collect($result)->firstWhere('run_id', 'pw-b');
    expect($recB['route_plan'])->toBeNull()
        ->and($recB['failure'])->toBeNull()
        ->and($recB['retry_policy'])->toBeNull()
        ->and($recB['node_states']['step:0']['status'])->toBe('solo');
});

test('parentsWaitingOnTerminalChildren issues a constant query count regardless of candidate count', function () {
    $store = app(DatabaseDurableRunStore::class);
    $now = now('UTC');

    foreach (range(1, 6) as $i) {
        seedDurableRun("pw-count-$i", ['status' => 'waiting']);
        seedTerminalChild("pw-count-$i", "pw-count-$i-child", 'completed');
        DB::table('swarm_durable_run_state')->insert([
            'run_id' => "pw-count-$i", 'route_plan' => null, 'route_plan_projected' => false,
            'failure' => null, 'retry_policy' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('swarm_durable_node_states')->insert([
            'run_id' => "pw-count-$i", 'node_id' => 'step:0', 'state' => json_encode(['s' => $i]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $result = $store->parentsWaitingOnTerminalChildren();

    expect($result)->toHaveCount(6)
        ->and($queries)->toBe(3);
});

test('parentsWaitingOnTerminalChildren returns one row per terminal child (no dedup) and stays constant in side-table loads', function () {
    $store = app(DatabaseDurableRunStore::class);
    $now = now('UTC');

    // A single waiting parent with three terminal children → three identical rows.
    seedDurableRun('pw-dup', ['status' => 'waiting']);
    seedTerminalChild('pw-dup', 'pw-dup-c1', 'completed');
    seedTerminalChild('pw-dup', 'pw-dup-c2', 'failed');
    seedTerminalChild('pw-dup', 'pw-dup-c3', 'cancelled');
    DB::table('swarm_durable_node_states')->insert([
        'run_id' => 'pw-dup', 'node_id' => 'step:0', 'state' => json_encode(['status' => 'solo']),
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $result = $store->parentsWaitingOnTerminalChildren();

    // N terminal children → N identical parent rows (preserved, not deduped).
    // Assert the sweep's own query count BEFORE the find()-equality check, whose
    // per-record find() calls would otherwise be counted by the same listener.
    expect($result)->toHaveCount(3)
        ->and(collect($result)->pluck('run_id')->all())->toBe(['pw-dup', 'pw-dup', 'pw-dup'])
        // Candidate select + two side-table loads, regardless of the duplicate rows.
        ->and($queries)->toBe(3)
        ->and($result)->toEqual(legacyFindPerRecord($store, $result));
});

/**
 * Deterministic guard for the unguarded run_state read-modify-write in
 * upsertRunState() (#273). These stage the exact cancel-vs-advance interleaving
 * sequentially — no process driver, no timing — so they prove the serialization
 * the upsertRunState() docblock claims, run on the default SQLite connection in
 * CI, and never go vacuous. The multi-process counterpart
 * (tests/ProcessConcurrency/DurableRunStateConcurrencyTest.php) confirms the
 * same invariant under genuine concurrent load on MySQL/Postgres.
 */
function seedRunStateRow(string $runId): void
{
    $now = now('UTC');

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

test('cancel between lease acquisition and the terminal write forces the advancer to roll back', function () {
    seedDurableRun('serial-cancel-wins', ['status' => 'pending']);
    seedRunStateRow('serial-cancel-wins');

    $store = app(DatabaseDurableRunStore::class);

    // Advancer takes the lease; the run row is still `pending` (a lease does not
    // change status), so cancel() can still flip it.
    $token = $store->acquireLease('serial-cancel-wins', 0, 300);
    expect($token)->not->toBeNull();

    // cancel() flips pending -> cancelled and nulls the lease under the main-row
    // write — the advancer's token is now stale.
    expect($store->cancel('serial-cancel-wins'))->toBeTrue();

    // The advancer's terminal write therefore loses its lease and rolls back the
    // whole transaction, including its run_state upsert.
    expect(fn () => $store->markFailed('serial-cancel-wins', $token, [
        'class' => RuntimeException::class,
        'message' => 'advance-sentinel',
    ]))->toThrow(LostDurableLeaseException::class);

    $run = DB::table('swarm_durable_runs')->where('run_id', 'serial-cancel-wins')->first();
    $state = DB::table('swarm_durable_run_state')->where('run_id', 'serial-cancel-wins')->first();

    // cancel-win, with no torn merge: the advancer's failure never landed.
    expect($run->status)->toBe('cancelled')
        ->and($state->failure)->toBeNull();
});

test('an advancer that commits before cancel makes cancel a no-op', function () {
    seedDurableRun('serial-advance-wins', ['status' => 'pending']);
    seedRunStateRow('serial-advance-wins');

    $store = app(DatabaseDurableRunStore::class);

    $token = $store->acquireLease('serial-advance-wins', 0, 300);
    expect($token)->not->toBeNull();

    // Advancer commits first: status -> failed, failure written to run_state.
    $store->markFailed('serial-advance-wins', $token, [
        'class' => RuntimeException::class,
        'message' => 'advance-sentinel',
    ]);

    // cancel() now finds the run terminal (status `failed` is not in
    // [pending, paused, waiting], nor `running`) — it never reaches the
    // run_state upsert and reports no cancellation.
    expect($store->cancel('serial-advance-wins'))->toBeFalse();

    $run = DB::table('swarm_durable_runs')->where('run_id', 'serial-advance-wins')->first();
    $state = DB::table('swarm_durable_run_state')->where('run_id', 'serial-advance-wins')->first();
    $failure = json_decode((string) $state->failure, true);

    // advance-win preserved: cancel did not clobber the committed failure.
    expect($run->status)->toBe('failed')
        ->and($failure['message'] ?? null)->toBe('advance-sentinel');
});
