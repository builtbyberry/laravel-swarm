<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
