<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Jobs\CompactSwarmRun;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    config()->set('swarm.persistence.driver', 'database');
});

/**
 * Insert the FK parent rows required before inserting stream events or durable runs.
 */
function cmd_seedRunHistory(string $runId): void
{
    $now = now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'running',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * Insert a durable run row (required to set compaction_quarantined_at).
 */
function cmd_seedDurableRun(string $runId): void
{
    $now = now('UTC');

    DB::table('swarm_durable_runs')->insert([
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
        'attempts' => 1,
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
}

/**
 * Insert a seal barrier directly into the hot event log.
 */
function cmd_insertBarrier(string $runId): void
{
    $now = now('UTC');

    DB::table('swarm_stream_events')->insert([
        'run_id' => $runId,
        'event_type' => 'swarm_causal_seal_barrier',
        'payload' => json_encode(['type' => 'swarm_causal_seal_barrier', 'run_id' => $runId]),
        'expires_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// ─── Scenario 1: Discovery dispatches jobs for runs with barriers ─────────────

test('swarm:compact discovers runs with barriers and dispatches one job per run', function (): void {
    Queue::fake();

    cmd_seedRunHistory('run-cmd-a');
    cmd_seedRunHistory('run-cmd-b');
    cmd_insertBarrier('run-cmd-a');
    cmd_insertBarrier('run-cmd-b');

    $exitCode = Artisan::call('swarm:compact');

    expect($exitCode)->toBe(0);

    Queue::assertPushed(CompactSwarmRun::class, 2);
    Queue::assertPushed(CompactSwarmRun::class, fn (CompactSwarmRun $job): bool => $job->runId === 'run-cmd-a');
    Queue::assertPushed(CompactSwarmRun::class, fn (CompactSwarmRun $job): bool => $job->runId === 'run-cmd-b');
});

// ─── Scenario 2: Quarantined runs are excluded from discovery ─────────────────

test('swarm:compact excludes quarantined runs from discovery', function (): void {
    Queue::fake();

    cmd_seedRunHistory('run-cmd-ok');
    cmd_seedRunHistory('run-cmd-quarantined');
    cmd_seedDurableRun('run-cmd-ok');
    cmd_seedDurableRun('run-cmd-quarantined');

    cmd_insertBarrier('run-cmd-ok');
    cmd_insertBarrier('run-cmd-quarantined');

    DB::table('swarm_durable_runs')
        ->where('run_id', 'run-cmd-quarantined')
        ->update(['compaction_quarantined_at' => now('UTC')]);

    Artisan::call('swarm:compact');

    Queue::assertPushed(CompactSwarmRun::class, 1);
    Queue::assertPushed(CompactSwarmRun::class, fn (CompactSwarmRun $job): bool => $job->runId === 'run-cmd-ok');
    Queue::assertNotPushed(CompactSwarmRun::class, fn (CompactSwarmRun $job): bool => $job->runId === 'run-cmd-quarantined');
});

// ─── Scenario 3: --run-id bypasses discovery ──────────────────────────────────

test('swarm:compact --run-id dispatches exactly one job regardless of other eligible runs', function (): void {
    Queue::fake();

    cmd_seedRunHistory('run-cmd-target');
    cmd_seedRunHistory('run-cmd-other');
    cmd_insertBarrier('run-cmd-target');
    cmd_insertBarrier('run-cmd-other');

    Artisan::call('swarm:compact', ['--run-id' => 'run-cmd-target']);

    Queue::assertPushed(CompactSwarmRun::class, 1);
    Queue::assertPushed(CompactSwarmRun::class, fn (CompactSwarmRun $job): bool => $job->runId === 'run-cmd-target');
    Queue::assertNotPushed(CompactSwarmRun::class, fn (CompactSwarmRun $job): bool => $job->runId === 'run-cmd-other');
});

// ─── Scenario 4: --limit caps the discovery result set ───────────────────────

test('swarm:compact --limit=2 dispatches at most 2 jobs when 3 eligible runs exist', function (): void {
    Queue::fake();

    cmd_seedRunHistory('run-cmd-limit-1');
    cmd_seedRunHistory('run-cmd-limit-2');
    cmd_seedRunHistory('run-cmd-limit-3');
    cmd_insertBarrier('run-cmd-limit-1');
    cmd_insertBarrier('run-cmd-limit-2');
    cmd_insertBarrier('run-cmd-limit-3');

    Artisan::call('swarm:compact', ['--limit' => '2']);

    Queue::assertPushed(CompactSwarmRun::class, 2);
});

// ─── Scenario 5: No eligible runs → info message, exit 0 ─────────────────────

test('swarm:compact with no eligible runs prints info and exits zero', function (): void {
    Queue::fake();

    $exitCode = Artisan::call('swarm:compact');

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('No runs');
    Queue::assertNothingPushed();
});

// ─── Scenario 6: command.compact audit event emitted ─────────────────────────

test('swarm:compact emits a command.compact audit event with dispatched_count', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    cmd_seedRunHistory('run-cmd-audit-1');
    cmd_seedRunHistory('run-cmd-audit-2');
    cmd_insertBarrier('run-cmd-audit-1');
    cmd_insertBarrier('run-cmd-audit-2');

    Artisan::call('swarm:compact');

    $records = $sink->recordsForCategory('command.compact');
    expect($records)->toHaveCount(1);

    $payload = $records[0];
    expect($payload['dispatched_count'])->toBe(2);
    expect($payload['status'])->toBe('dispatched');
    expect($payload['target_run_id'])->toBeNull();
});
