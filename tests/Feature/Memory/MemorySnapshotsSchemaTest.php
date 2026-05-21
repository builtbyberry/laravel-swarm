<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifies the swarm_memory_snapshots schema added by the
 * 2026_05_21_000001_create_swarm_memory_snapshots_table migration.
 *
 * SQLite with foreign_key_constraints=true (configured in TestCase) enforces
 * PRAGMA foreign_keys=ON so all FK assertions work against the in-memory DB.
 */
beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

function insertHistoryParentRow(string $runId): void
{
    $now = Carbon::now('UTC');

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

// ---------------------------------------------------------------------------
// Schema shape
// ---------------------------------------------------------------------------

test('the swarm_memory_snapshots table exists with the expected columns', function () {
    expect(Schema::hasTable('swarm_memory_snapshots'))->toBeTrue();

    expect(Schema::hasColumns('swarm_memory_snapshots', [
        'id',
        'run_id',
        'step_index',
        'payload',
        'tool_calls',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

test('migration rolls back cleanly', function () {
    // Rolls back the snapshots migration only. The migration we just added is
    // top of the stack so --step=1 removes it.
    Artisan::call('migrate:rollback', ['--database' => 'testing', '--step' => 1]);

    expect(Schema::hasTable('swarm_memory_snapshots'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// JSON round-trip
// ---------------------------------------------------------------------------

test('payload and tool_calls JSON columns round-trip arbitrary plain-data shapes', function () {
    insertHistoryParentRow('run-json-trip');

    $payload = [
        'scope' => 'run',
        'entries' => [
            ['key' => 'last_output', 'value' => 'hello'],
            ['key' => 'preferences', 'value' => ['tone' => 'casual', 'length' => 'short']],
        ],
    ];

    $toolCalls = [
        ['tool' => 'Recall', 'input' => ['key' => 'user_pref'], 'output' => 'dark mode'],
    ];

    DB::table('swarm_memory_snapshots')->insert([
        'run_id' => 'run-json-trip',
        'step_index' => 0,
        'payload' => json_encode($payload),
        'tool_calls' => json_encode($toolCalls),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var object $row */
    $row = DB::table('swarm_memory_snapshots')->where('run_id', 'run-json-trip')->first();

    expect(json_decode((string) $row->payload, true))->toEqual($payload);
    expect(json_decode((string) $row->tool_calls, true))->toEqual($toolCalls);
});

// ---------------------------------------------------------------------------
// Composite uniqueness — one snapshot per step
// ---------------------------------------------------------------------------

test('a second snapshot for the same (run_id, step_index) fails the unique constraint', function () {
    insertHistoryParentRow('run-unique-check');

    DB::table('swarm_memory_snapshots')->insert([
        'run_id' => 'run-unique-check',
        'step_index' => 0,
        'payload' => json_encode(['first' => true]),
        'tool_calls' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('swarm_memory_snapshots')->insert([
        'run_id' => 'run-unique-check',
        'step_index' => 0,
        'payload' => json_encode(['second' => true]),
        'tool_calls' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the same step_index across different run_ids is allowed', function () {
    insertHistoryParentRow('run-A');
    insertHistoryParentRow('run-B');

    DB::table('swarm_memory_snapshots')->insert([
        'run_id' => 'run-A',
        'step_index' => 0,
        'payload' => json_encode(['from' => 'A']),
        'tool_calls' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('swarm_memory_snapshots')->insert([
        'run_id' => 'run-B',
        'step_index' => 0,
        'payload' => json_encode(['from' => 'B']),
        'tool_calls' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('swarm_memory_snapshots')->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// FK enforcement and cascade
// ---------------------------------------------------------------------------

test('inserting a snapshot row without a swarm_run_histories parent fails FK constraint', function () {
    expect(fn () => DB::table('swarm_memory_snapshots')->insert([
        'run_id' => 'never-existed',
        'step_index' => 0,
        'payload' => json_encode(['orphan' => true]),
        'tool_calls' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('deleting a swarm_run_histories row cascades to its snapshot rows', function () {
    insertHistoryParentRow('run-to-be-purged');

    DB::table('swarm_memory_snapshots')->insert([
        'run_id' => 'run-to-be-purged',
        'step_index' => 0,
        'payload' => json_encode(['anything' => true]),
        'tool_calls' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('swarm_memory_snapshots')->where('run_id', 'run-to-be-purged')->exists())->toBeTrue();

    DB::table('swarm_run_histories')->where('run_id', 'run-to-be-purged')->delete();

    expect(DB::table('swarm_memory_snapshots')->where('run_id', 'run-to-be-purged')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Byte-stable payload — same input JSON in, same string out
// ---------------------------------------------------------------------------

test('payload bytes are preserved verbatim across persist and read', function () {
    insertHistoryParentRow('run-byte-stable');

    // Order-stable JSON. The schema must not re-encode or rewrite the string —
    // byte stability is a hard requirement of compliance-grade replay.
    $encoded = '{"a":1,"b":"two","c":[true,false,null],"d":{"nested":3.14}}';

    DB::table('swarm_memory_snapshots')->insert([
        'run_id' => 'run-byte-stable',
        'step_index' => 0,
        'payload' => $encoded,
        'tool_calls' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var object $row */
    $row = DB::table('swarm_memory_snapshots')->where('run_id', 'run-byte-stable')->first();

    expect((string) $row->payload)->toBe($encoded);
});
