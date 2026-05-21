<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifies the swarm_memories schema added by the
 * 2026_05_21_000002_create_swarm_memories_table migration.
 *
 * Targets the three indexed hot paths (propagation reads, value lookups,
 * retention purges) plus the unique-address invariant the DatabaseMemoryStore
 * relies on for upsert semantics.
 */
beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

// ---------------------------------------------------------------------------
// Schema shape
// ---------------------------------------------------------------------------

test('the swarm_memories table exists with the expected columns', function () {
    expect(Schema::hasTable('swarm_memories'))->toBeTrue();

    expect(Schema::hasColumns('swarm_memories', [
        'id',
        'scope',
        'scope_id',
        'key',
        'value',
        'metadata',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

test('migration up/down is idempotent', function () {
    // Top of the stack — rolling back step=1 removes the memories table
    // (and only the memories table; #110's snapshots migration is below us).
    Artisan::call('migrate:rollback', ['--database' => 'testing', '--step' => 1]);
    expect(Schema::hasTable('swarm_memories'))->toBeFalse();

    // And re-running migrate brings it back cleanly.
    Artisan::call('migrate', ['--database' => 'testing']);
    expect(Schema::hasTable('swarm_memories'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Index existence — covering the three hot paths
// ---------------------------------------------------------------------------

test('the propagation-read composite index (scope, scope_id) exists', function () {
    $indexes = collect(Schema::getIndexes('swarm_memories'))
        ->map(fn (array $index): array => $index['columns']);

    expect($indexes->contains(fn (array $cols): bool => $cols === ['scope', 'scope_id']))->toBeTrue();
});

test('the value-lookup composite index (scope, key) exists', function () {
    $indexes = collect(Schema::getIndexes('swarm_memories'))
        ->map(fn (array $index): array => $index['columns']);

    expect($indexes->contains(fn (array $cols): bool => $cols === ['scope', 'key']))->toBeTrue();
});

test('the retention-purge index on created_at exists', function () {
    $indexes = collect(Schema::getIndexes('swarm_memories'))
        ->map(fn (array $index): array => $index['columns']);

    expect($indexes->contains(fn (array $cols): bool => $cols === ['created_at']))->toBeTrue();
});

test('the unique (scope, scope_id, key) index exists and is marked unique', function () {
    $uniques = collect(Schema::getIndexes('swarm_memories'))
        ->filter(fn (array $index): bool => $index['unique'] === true)
        ->map(fn (array $index): array => $index['columns'])
        ->values();

    expect($uniques->contains(fn (array $cols): bool => $cols === ['scope', 'scope_id', 'key']))->toBeTrue();
});

// ---------------------------------------------------------------------------
// JSON column round-trip
// ---------------------------------------------------------------------------

test('value and metadata JSON columns round-trip arbitrary plain-data shapes', function () {
    $now = now();

    $value = ['nested' => ['list' => [1, 2, 3], 'flag' => true, 'maybe' => null]];
    $metadata = ['source' => 'unit-test', 'redacted_fields' => ['pii.email']];

    DB::table('swarm_memories')->insert([
        'scope' => 'run',
        'scope_id' => 'run-json',
        'key' => 'pref',
        'value' => json_encode($value),
        'metadata' => json_encode($metadata),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    /** @var object $row */
    $row = DB::table('swarm_memories')->where('scope_id', 'run-json')->first();

    expect(json_decode((string) $row->value, true))->toEqual($value);
    expect(json_decode((string) $row->metadata, true))->toEqual($metadata);
});

// ---------------------------------------------------------------------------
// Uniqueness — one row per (scope, scope_id, key)
// ---------------------------------------------------------------------------

test('a second row with the same (scope, scope_id, key) fails the unique constraint', function () {
    $now = now();

    DB::table('swarm_memories')->insert([
        'scope' => 'run',
        'scope_id' => 'run-1',
        'key' => 'last_output',
        'value' => json_encode('first'),
        'metadata' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(fn () => DB::table('swarm_memories')->insert([
        'scope' => 'run',
        'scope_id' => 'run-1',
        'key' => 'last_output',
        'value' => json_encode('second'),
        'metadata' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});

test('the same key is allowed under a different scope', function () {
    $now = now();

    DB::table('swarm_memories')->insert([
        'scope' => 'run',
        'scope_id' => 'shared-id',
        'key' => 'pref',
        'value' => json_encode('run-value'),
        'metadata' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('swarm_memories')->insert([
        'scope' => 'conversation',
        'scope_id' => 'shared-id',
        'key' => 'pref',
        'value' => json_encode('convo-value'),
        'metadata' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(DB::table('swarm_memories')->count())->toBe(2);
});

test('the same key is allowed under a different scope_id', function () {
    $now = now();

    DB::table('swarm_memories')->insert([
        'scope' => 'run',
        'scope_id' => 'run-A',
        'key' => 'last_output',
        'value' => json_encode('A'),
        'metadata' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('swarm_memories')->insert([
        'scope' => 'run',
        'scope_id' => 'run-B',
        'key' => 'last_output',
        'value' => json_encode('B'),
        'metadata' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(DB::table('swarm_memories')->count())->toBe(2);
});
