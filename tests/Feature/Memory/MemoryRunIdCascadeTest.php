<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the run_id FK cascade on swarm_memories added by
 * 2026_05_21_000003_add_run_id_to_swarm_memories_table.
 *
 * Run-scoped memory rows carry a denormalized run_id that FKs into
 * swarm_run_histories with ON DELETE CASCADE. Deleting a history row must
 * synchronously remove its Run-scoped memories (the GDPR/CCPA "delete me and
 * my data" path) without waiting on the retention purge. Non-Run-scoped rows
 * leave run_id NULL and survive the parent delete.
 *
 * SQLite with foreign_key_constraints=true (configured in TestCase) enforces
 * PRAGMA foreign_keys=ON so all FK assertions work against the in-memory DB.
 */
beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    config()->set('swarm.persistence.driver', 'database');
});

function insertMemoryParentHistoryRow(string $runId): void
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

test('deleting a swarm_run_histories row cascades to its Run-scoped memory rows', function () {
    $runId = 'cascade-mem-run';
    insertMemoryParentHistoryRow($runId);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, $runId, 'last_output', 'agent said hello'));

    expect(DB::table('swarm_memories')->where('scope', 'run')->where('scope_id', $runId)->exists())->toBeTrue();

    DB::table('swarm_run_histories')->where('run_id', $runId)->delete();

    expect(DB::table('swarm_memories')->where('scope', 'run')->where('scope_id', $runId)->exists())->toBeFalse();
});

test('non-Run-scoped rows survive parent history delete when scope_id happens to match a run_id', function () {
    // Same string is used as a Run scope_id (FK-bound) and a Conversation
    // scope_id (FK-free). Only the Run row should cascade-delete; the
    // Conversation row's scope_id collision is incidental and irrelevant.
    $runId = 'shared-id-cascade';
    insertMemoryParentHistoryRow($runId);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, $runId, 'k', 'run-value'));
    $store->put(new MemoryEntry(MemoryScope::Conversation, $runId, 'k', 'convo-value'));

    expect(DB::table('swarm_memories')->count())->toBe(2);

    DB::table('swarm_run_histories')->where('run_id', $runId)->delete();

    // Run-scoped row is gone via FK cascade.
    expect(DB::table('swarm_memories')->where('scope', 'run')->exists())->toBeFalse();

    // Conversation-scoped row survives — its run_id is null, no cascade applies.
    $survivor = DB::table('swarm_memories')->where('scope', 'conversation')->first();
    expect($survivor)->not->toBeNull()
        ->and($survivor->run_id)->toBeNull()
        ->and($survivor->scope_id)->toBe($runId);
});

test('Agent- and Swarm-scoped rows are unaffected by history deletes', function () {
    $runId = 'history-going-away';
    insertMemoryParentHistoryRow($runId);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, $runId, 'k', 'run-value'));
    $store->put(new MemoryEntry(MemoryScope::Agent, 'App\\Agents\\Foo', 'k', 'agent-value'));
    $store->put(new MemoryEntry(MemoryScope::Swarm, 'App\\Swarms\\Bar', 'k', 'swarm-value'));

    DB::table('swarm_run_histories')->where('run_id', $runId)->delete();

    expect(DB::table('swarm_memories')->where('scope', 'run')->exists())->toBeFalse()
        ->and(DB::table('swarm_memories')->where('scope', 'agent')->exists())->toBeTrue()
        ->and(DB::table('swarm_memories')->where('scope', 'swarm')->exists())->toBeTrue();
});
