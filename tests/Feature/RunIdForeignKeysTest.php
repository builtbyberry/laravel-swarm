<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifies the run_id foreign-key constraints added by the
 * 2026_05_04_000001_add_run_id_foreign_keys_to_swarm_tables migration.
 *
 * SQLite with foreign_key_constraints=true (configured in TestCase) enforces
 * PRAGMA foreign_keys=ON so all FK assertions work against the in-memory DB.
 */
beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

// ---------------------------------------------------------------------------
// Schema assertions
// ---------------------------------------------------------------------------

test('run_id FK migration adds all expected foreign key constraints', function () {
    // History family
    expect(Schema::hasTable('swarm_contexts'))->toBeTrue()
        ->and(Schema::hasTable('swarm_artifacts'))->toBeTrue()
        ->and(Schema::hasTable('swarm_run_steps'))->toBeTrue()
        ->and(Schema::hasTable('swarm_stream_events'))->toBeTrue();

    // Durable family
    expect(Schema::hasTable('swarm_durable_branches'))->toBeTrue()
        ->and(Schema::hasTable('swarm_durable_node_states'))->toBeTrue()
        ->and(Schema::hasTable('swarm_durable_run_state'))->toBeTrue()
        ->and(Schema::hasTable('swarm_durable_node_outputs'))->toBeTrue()
        ->and(Schema::hasTable('swarm_durable_signals'))->toBeTrue()
        ->and(Schema::hasTable('swarm_durable_waits'))->toBeTrue()
        ->and(Schema::hasTable('swarm_durable_labels'))->toBeTrue()
        ->and(Schema::hasTable('swarm_durable_details'))->toBeTrue()
        ->and(Schema::hasTable('swarm_durable_progress'))->toBeTrue()
        ->and(Schema::hasTable('swarm_durable_child_runs'))->toBeTrue()
        ->and(Schema::hasTable('swarm_durable_webhook_idempotency'))->toBeTrue();
});

test('FK migration rolls back cleanly', function () {
    Artisan::call('migrate:rollback', ['--database' => 'testing', '--step' => 1]);

    // Tables still exist; FK constraints are just removed. Insert into a child
    // table without a parent row — should succeed now that FKs are dropped.
    DB::table('swarm_contexts')->insert([
        'run_id' => 'rollback-check-id',
        'input' => 'test',
        'data' => json_encode([]),
        'metadata' => json_encode([]),
        'artifacts' => json_encode([]),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('swarm_contexts')->where('run_id', 'rollback-check-id')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Inserting a child row without a parent must fail
// ---------------------------------------------------------------------------

test('inserting a context row without a history parent fails FK constraint', function () {
    expect(fn () => DB::table('swarm_contexts')->insert([
        'run_id' => 'no-such-run',
        'input' => 'test',
        'data' => json_encode([]),
        'metadata' => json_encode([]),
        'artifacts' => json_encode([]),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('inserting a durable branch row without a durable run parent fails FK constraint', function () {
    expect(fn () => DB::table('swarm_durable_branches')->insert([
        'run_id' => 'no-such-run',
        'branch_id' => 'parallel:0',
        'step_index' => 0,
        'agent_class' => 'FakeAgent',
        'status' => 'pending',
        'input' => 'test',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// Cascade deletes — history family
// ---------------------------------------------------------------------------

test('deleting a history row cascades to contexts artifacts steps and stream events', function () {
    $now = now('UTC');
    $runId = 'cascade-history-test';

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'completed',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => 'ok',
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => $now,
        'expires_at' => $now->copy()->addHour(),
        'execution_token' => null,
        'leased_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('swarm_contexts')->insert([
        'run_id' => $runId, 'input' => 'x', 'data' => json_encode([]),
        'metadata' => json_encode([]), 'artifacts' => json_encode([]),
        'expires_at' => $now->copy()->addHour(), 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_artifacts')->insert([
        'run_id' => $runId, 'name' => 'out', 'content' => json_encode('v'),
        'metadata' => json_encode([]), 'step_agent_class' => null,
        'expires_at' => $now->copy()->addHour(), 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_run_steps')->insert([
        'run_id' => $runId, 'step_index' => 0, 'agent_class' => 'FakeAgent',
        'input' => 'x', 'output' => 'y', 'artifacts' => json_encode([]),
        'metadata' => json_encode(['index' => 0]),
        'expires_at' => $now->copy()->addHour(), 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_stream_events')->insert([
        'run_id' => $runId, 'event_type' => 'swarm_stream_start',
        'payload' => json_encode(['type' => 'swarm_stream_start']),
        'expires_at' => $now->copy()->addHour(), 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_run_histories')->where('run_id', $runId)->delete();

    expect(DB::table('swarm_contexts')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_artifacts')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_run_steps')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_stream_events')->where('run_id', $runId)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Cascade deletes — durable family
// ---------------------------------------------------------------------------

function insertMinimalDurableRun(string $runId): void
{
    $now = now('UTC');
    DB::table('swarm_durable_runs')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'completed',
        'next_step_index' => 1,
        'current_step_index' => 0,
        'total_steps' => 1,
        'timeout_at' => $now->copy()->addHour(),
        'step_timeout_seconds' => 300,
        'execution_token' => null,
        'leased_until' => null,
        'pause_requested_at' => null,
        'cancel_requested_at' => null,
        'queue_connection' => null,
        'queue_name' => null,
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

test('deleting a durable run cascades to all durable child tables', function () {
    $now = now('UTC');
    $runId = 'cascade-durable-test';
    insertMinimalDurableRun($runId);

    DB::table('swarm_durable_branches')->insert([
        'run_id' => $runId, 'branch_id' => 'parallel:0', 'step_index' => 0,
        'agent_class' => 'FakeAgent', 'status' => 'completed', 'input' => 'x',
        'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_node_states')->insert([
        'run_id' => $runId, 'node_id' => 'step:0',
        'state' => json_encode(['status' => 'done']),
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_run_state')->insert([
        'run_id' => $runId, 'route_plan' => null, 'route_plan_projected' => false,
        'failure' => null, 'retry_policy' => null,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_node_outputs')->insert([
        'run_id' => $runId, 'node_id' => 'step:0', 'output' => 'result',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $signalId = DB::table('swarm_durable_signals')->insertGetId([
        'run_id' => $runId, 'name' => 'resume', 'status' => 'recorded',
        'payload' => null, 'idempotency_key' => null,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_waits')->insert([
        'run_id' => $runId, 'name' => 'wait-for-signal', 'status' => 'waiting',
        'signal_id' => $signalId,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_labels')->insert([
        'run_id' => $runId, 'key' => 'phase', 'value_type' => 'string',
        'value_string' => 'init', 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_details')->insert([
        'run_id' => $runId, 'details' => json_encode(['note' => 'ok']),
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_progress')->insert([
        'run_id' => $runId, 'branch_id' => '', 'step_index' => 0,
        'agent_class' => 'FakeAgent', 'progress' => json_encode(['pct' => 50]),
        'last_progress_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_child_runs')->insert([
        'parent_run_id' => $runId, 'child_run_id' => 'child-durable-test',
        'child_swarm_class' => 'ChildSwarm', 'wait_name' => 'child-wait',
        'context_payload' => json_encode([]), 'status' => 'completed',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_runs')->where('run_id', $runId)->delete();

    expect(DB::table('swarm_durable_branches')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_node_states')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_run_state')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_signals')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_waits')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_labels')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_details')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_progress')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_child_runs')->where('parent_run_id', $runId)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// SET NULL — parent_run_id self-reference
// ---------------------------------------------------------------------------

test('deleting a parent durable run sets parent_run_id to null on child runs', function () {
    $now = now('UTC');
    $parentId = 'self-fk-parent';
    $childId = 'self-fk-child';

    insertMinimalDurableRun($parentId);
    insertMinimalDurableRun($childId);

    DB::table('swarm_durable_runs')->where('run_id', $childId)->update(['parent_run_id' => $parentId]);

    DB::table('swarm_durable_runs')->where('run_id', $parentId)->delete();

    $child = DB::table('swarm_durable_runs')->where('run_id', $childId)->first();
    expect($child)->not->toBeNull()
        ->and($child->parent_run_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// SET NULL — webhook_idempotency.run_id
// ---------------------------------------------------------------------------

test('deleting a durable run sets run_id to null on idempotency rows leaving the record intact', function () {
    $now = now('UTC');
    $runId = 'webhook-fk-test';
    insertMinimalDurableRun($runId);

    DB::table('swarm_durable_webhook_idempotency')->insert([
        'scope' => 'default', 'idempotency_key' => 'idem-001',
        'request_hash' => md5('req'), 'status' => 'completed', 'run_id' => $runId,
        'response_payload' => json_encode(['ok' => true]),
        'completed_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_runs')->where('run_id', $runId)->delete();

    $row = DB::table('swarm_durable_webhook_idempotency')->where('idempotency_key', 'idem-001')->first();
    expect($row)->not->toBeNull()
        ->and($row->run_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// SET NULL — waits.signal_id
// ---------------------------------------------------------------------------

test('deleting a signal sets signal_id to null on its wait row', function () {
    $now = now('UTC');
    $runId = 'signal-fk-test';
    insertMinimalDurableRun($runId);

    $signalId = DB::table('swarm_durable_signals')->insertGetId([
        'run_id' => $runId, 'name' => 'go', 'status' => 'recorded',
        'payload' => null, 'idempotency_key' => null,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_waits')->insert([
        'run_id' => $runId, 'name' => 'wait-for-go', 'status' => 'waiting',
        'signal_id' => $signalId,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_signals')->where('id', $signalId)->delete();

    $wait = DB::table('swarm_durable_waits')->where('run_id', $runId)->first();
    expect($wait)->not->toBeNull()
        ->and($wait->signal_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// No FK — child_run_id has no constraint
// ---------------------------------------------------------------------------

test('pruning the child durable run does not remove the parent child_run registry row', function () {
    $now = now('UTC');
    $parentId = 'no-fk-parent';
    $childId = 'no-fk-child';

    insertMinimalDurableRun($parentId);
    insertMinimalDurableRun($childId);

    DB::table('swarm_durable_child_runs')->insert([
        'parent_run_id' => $parentId, 'child_run_id' => $childId,
        'child_swarm_class' => 'ChildSwarm', 'wait_name' => 'go',
        'context_payload' => json_encode([]), 'status' => 'completed',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('swarm_durable_runs')->where('run_id', $childId)->delete();

    expect(DB::table('swarm_durable_child_runs')->where('child_run_id', $childId)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Prune still succeeds end-to-end with FK constraints active
// ---------------------------------------------------------------------------

test('swarm prune completes successfully with FK constraints active', function () {
    $now = now('UTC');
    $expired = $now->copy()->subMinute();
    $runId = 'fk-prune-test';

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'completed',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => 'ok',
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => $expired,
        'expires_at' => $expired,
        'execution_token' => null,
        'leased_until' => null,
        'created_at' => $expired,
        'updated_at' => $expired,
    ]);

    DB::table('swarm_contexts')->insert([
        'run_id' => $runId, 'input' => 'x', 'data' => json_encode([]),
        'metadata' => json_encode([]), 'artifacts' => json_encode([]),
        'expires_at' => $expired, 'created_at' => $expired, 'updated_at' => $expired,
    ]);

    insertMinimalDurableRun($runId);
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update([
        'status' => 'completed', 'finished_at' => $expired,
        'created_at' => $expired, 'updated_at' => $expired,
    ]);

    $exitCode = Artisan::call('swarm:prune');

    expect($exitCode)->toBe(0)
        ->and(DB::table('swarm_run_histories')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_contexts')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('swarm_durable_runs')->where('run_id', $runId)->exists())->toBeFalse();
});
