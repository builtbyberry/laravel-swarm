<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Exceptions\SnapshotFrozenException;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemorySnapshotRecorder;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Tests\Support\InMemoryMemoryStore;
use BuiltByBerry\LaravelSwarm\Tests\Support\ThrowingMemoryStore;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;

beforeEach(function () {
    DB::table('swarm_run_histories')->insert([
        'run_id' => 'run-snap',
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
        'created_at' => Carbon::now('UTC'),
        'updated_at' => Carbon::now('UTC'),
    ]);

    // Bind an in-memory MemoryStore so the recorder can read entries without
    // needing the swarm_memories table (which lives in a sibling worktree).
    $this->app->singleton(MemoryStore::class, fn (): MemoryStore => new InMemoryMemoryStore);
    $this->app->singleton(SwarmMemory::class, DefaultSwarmMemory::class);
    $this->app->singleton(SnapshotsMemory::class, function (): SnapshotsMemory {
        return new DatabaseMemorySnapshotRecorder(
            connection: $this->app->make(Connection::class),
            config: $this->app->make('config'),
            memory: $this->app->make(SwarmMemory::class),
            logger: $this->app->make(LoggerInterface::class),
            events: $this->app->make(Dispatcher::class),
        );
    });
});

// ---------------------------------------------------------------------------
// MemorySnapshot value object
// ---------------------------------------------------------------------------

test('MemorySnapshot::fromEntries freezes the agent-visible view and preserves order', function () {
    $entries = [
        new MemoryEntry(MemoryScope::Run, 'run-1', 'first', 'a'),
        new MemoryEntry(MemoryScope::Run, 'run-1', 'second', ['nested' => true]),
    ];

    $snapshot = MemorySnapshot::fromEntries('run-1', 0, $entries);

    expect($snapshot->runId)->toBe('run-1');
    expect($snapshot->stepIndex)->toBe(0);
    expect($snapshot->entries)->toHaveCount(2);
    expect($snapshot->entries[0]['key'])->toBe('first');
    expect($snapshot->entries[1]['key'])->toBe('second');
    expect($snapshot->entries[1]['value'])->toBe(['nested' => true]);
    expect($snapshot->toolCalls)->toBe([]);
});

test('MemorySnapshot::withToolCall returns an immutable copy with the appended entry', function () {
    $snapshot = new MemorySnapshot('run-1', 0, [], []);
    $next = $snapshot->withToolCall(['name' => 't', 'arguments' => [], 'result' => 'ok']);

    expect($snapshot->toolCalls)->toBe([]);
    expect($next->toolCalls)->toHaveCount(1);
    expect($next->toolCalls[0])->toMatchArray(['name' => 't', 'result' => 'ok']);
});

// ---------------------------------------------------------------------------
// DatabaseMemorySnapshotRecorder — persist + read back
// ---------------------------------------------------------------------------

test('snapshot persists the frozen view to swarm_memory_snapshots and returns a value object', function () {
    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);
    $memory->put(MemoryScope::Run, 'run-snap', 'last_output', 'previous-agent-output');
    $memory->put(MemoryScope::Run, 'run-snap', 'preferences', ['tone' => 'casual']);

    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);
    $snapshot = $recorder->snapshot('run-snap', 0);

    expect($snapshot->runId)->toBe('run-snap');
    expect($snapshot->stepIndex)->toBe(0);
    expect($snapshot->entries)->toHaveCount(2);
    expect($snapshot->toolCalls)->toBe([]);

    $row = DB::table('swarm_memory_snapshots')->where('run_id', 'run-snap')->where('step_index', 0)->first();
    expect($row)->not->toBeNull();
    /** @var object $row */
    $payload = json_decode((string) $row->payload, true);
    expect($payload['run_id'])->toBe('run-snap');
    expect($payload['step_index'])->toBe(0);
    expect($payload['entries'])->toHaveCount(2);
});

test('appendToolCall rewrites only the tool_calls JSON column and returns the updated snapshot', function () {
    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);
    $snapshot = $recorder->snapshot('run-snap', 0);

    $updated = $recorder->appendToolCall($snapshot, [
        'id' => 'call-1',
        'name' => 'Recall',
        'arguments' => ['key' => 'user_pref'],
        'result' => 'dark mode',
        'result_id' => 'r-1',
    ]);
    $updated = $recorder->appendToolCall($updated, [
        'id' => 'call-2',
        'name' => 'Recall',
        'arguments' => ['key' => 'language'],
        'result' => 'en',
        'result_id' => 'r-2',
    ]);

    expect($updated->toolCalls)->toHaveCount(2);

    $row = DB::table('swarm_memory_snapshots')->where('run_id', 'run-snap')->where('step_index', 0)->first();
    /** @var object $row */
    $toolCalls = json_decode((string) $row->tool_calls, true);
    expect($toolCalls)->toHaveCount(2);
    expect($toolCalls[0]['name'])->toBe('Recall');
    expect($toolCalls[1]['arguments'])->toBe(['key' => 'language']);
});

test('find rehydrates a persisted snapshot byte-identical to the original view', function () {
    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);
    $memory->put(MemoryScope::Run, 'run-snap', 'fact', ['source' => 'wiki', 'pages' => [1, 2, 3]]);

    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);
    $original = $recorder->snapshot('run-snap', 0);
    $recorder->appendToolCall($original, ['name' => 't', 'arguments' => [], 'result' => 'ok']);

    $rehydrated = $recorder->find('run-snap', 0);

    expect($rehydrated)->not->toBeNull();
    /** @var MemorySnapshot $rehydrated */
    expect($rehydrated->runId)->toBe($original->runId);
    expect($rehydrated->stepIndex)->toBe($original->stepIndex);
    expect($rehydrated->entries)->toBe($original->entries);
    expect($rehydrated->toolCalls)->toHaveCount(1);
});

test('snapshot upserts when called twice for the same (run_id, step_index)', function () {
    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);
    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);

    $memory->put(MemoryScope::Run, 'run-snap', 'k', 'v1');
    $recorder->snapshot('run-snap', 0);

    $memory->put(MemoryScope::Run, 'run-snap', 'k', 'v2');
    $recorder->snapshot('run-snap', 0);

    expect(DB::table('swarm_memory_snapshots')->where('run_id', 'run-snap')->count())->toBe(1);

    $rehydrated = $recorder->find('run-snap', 0);
    /** @var MemorySnapshot $rehydrated */
    expect($rehydrated->entries[0]['value'])->toBe('v2');
});

test('snapshot persists with an empty entries list when no memory has been written', function () {
    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);
    $snapshot = $recorder->snapshot('run-snap', 0);

    expect($snapshot->entries)->toBe([]);

    $row = DB::table('swarm_memory_snapshots')->where('run_id', 'run-snap')->where('step_index', 0)->first();
    /** @var object $row */
    expect(json_decode((string) $row->payload, true)['entries'])->toBe([]);
});

// ---------------------------------------------------------------------------
// Replay-determinism contract: frozen flag + appendToolCall guard + reset
// ---------------------------------------------------------------------------
//
// The canonical-record guard (`SnapshotFrozenException`) and the mid-flight
// retry reset (`resetToolCalls`) are the load-bearing contract additions for
// #112's replay-snapshot-determinism guarantee. See ReplaySwarmMemoryTest for
// the decorator-side coverage.

test('find returns a snapshot with frozen=true so the canonical-record guard fires', function () {
    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);
    $recorder->snapshot('run-snap', 0);

    $rehydrated = $recorder->find('run-snap', 0);

    expect($rehydrated)->not->toBeNull();
    /** @var MemorySnapshot $rehydrated */
    expect($rehydrated->frozen)->toBeTrue();
});

test('snapshot returns a fresh snapshot with frozen=false so appends to the in-flight invocation succeed', function () {
    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);
    $snapshot = $recorder->snapshot('run-snap', 0);

    expect($snapshot->frozen)->toBeFalse();
});

test('appendToolCall throws SnapshotFrozenException when handed a snapshot loaded via find()', function () {
    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);
    $recorder->snapshot('run-snap', 0);

    /** @var MemorySnapshot $frozen */
    $frozen = $recorder->find('run-snap', 0);

    expect(fn () => $recorder->appendToolCall($frozen, [
        'name' => 't',
        'arguments' => [],
        'result' => 'ok',
    ]))->toThrow(SnapshotFrozenException::class);

    // The canonical row must be untouched after the guard fires.
    $row = DB::table('swarm_memory_snapshots')->where('run_id', 'run-snap')->where('step_index', 0)->first();
    /** @var object $row */
    expect(json_decode((string) $row->tool_calls, true))->toBe([]);
});

test('resetToolCalls clears the persisted tool_calls column and returns an unfrozen snapshot', function () {
    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);
    $snapshot = $recorder->snapshot('run-snap', 0);
    $recorder->appendToolCall($snapshot, ['name' => 'first', 'arguments' => [], 'result' => 'a']);
    $recorder->appendToolCall($snapshot->withToolCall(['name' => 'first', 'arguments' => [], 'result' => 'a']), [
        'name' => 'second', 'arguments' => [], 'result' => 'b',
    ]);

    /** @var MemorySnapshot $frozen */
    $frozen = $recorder->find('run-snap', 0);
    expect($frozen->toolCalls)->toHaveCount(2);

    $cleared = $recorder->resetToolCalls($frozen);

    expect($cleared->toolCalls)->toBe([]);
    expect($cleared->frozen)->toBeFalse();

    $row = DB::table('swarm_memory_snapshots')->where('run_id', 'run-snap')->where('step_index', 0)->first();
    /** @var object $row */
    expect(json_decode((string) $row->tool_calls, true))->toBe([]);
});

test('after resetToolCalls a subsequent appendToolCall on the returned snapshot succeeds and persists', function () {
    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);
    $recorder->snapshot('run-snap', 0);

    /** @var MemorySnapshot $frozen */
    $frozen = $recorder->find('run-snap', 0);
    $reset = $recorder->resetToolCalls($frozen);

    $appended = $recorder->appendToolCall($reset, [
        'name' => 'rebuilt-on-retry', 'arguments' => [], 'result' => 'ok',
    ]);

    expect($appended->toolCalls)->toHaveCount(1);

    $row = DB::table('swarm_memory_snapshots')->where('run_id', 'run-snap')->where('step_index', 0)->first();
    /** @var object $row */
    $toolCalls = json_decode((string) $row->tool_calls, true);
    expect($toolCalls)->toHaveCount(1);
    expect($toolCalls[0]['name'])->toBe('rebuilt-on-retry');
});

// ---------------------------------------------------------------------------
// Payload size budget — a single snapshot should not balloon the row
// ---------------------------------------------------------------------------

test('snapshot payload stays within a reasonable byte budget for typical workloads', function () {
    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);

    // Write 25 entries of ~200 bytes of value each — representative of a
    // multi-step run that has accumulated typical agent state. The row should
    // stay well under the 64KB MEDIUMTEXT-or-larger ceiling we expect from
    // any reasonable JSON column.
    for ($i = 0; $i < 25; $i++) {
        $memory->put(
            MemoryScope::Run,
            'run-snap',
            "key-{$i}",
            ['index' => $i, 'note' => str_repeat('a', 200)],
        );
    }

    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);
    $recorder->snapshot('run-snap', 0);

    $row = DB::table('swarm_memory_snapshots')->where('run_id', 'run-snap')->where('step_index', 0)->first();
    /** @var object $row */
    expect(strlen((string) $row->payload))->toBeLessThan(32 * 1024);
});

// ---------------------------------------------------------------------------
// Hardened error handling — review F1 + F5
// ---------------------------------------------------------------------------
//
// The recorder used to wrap the Run-scoped memory read in a bare
// `catch (QueryException)` so the staged 0.9.0 rollout could land the
// snapshots migration ahead of the entries migration without the recorder
// failing every agent invocation. Both migrations now ship together, so the
// catch is reshaped into a `Schema::hasTable()` precheck — missing table
// degrades gracefully with a log line, but any *real* `QueryException`
// (connection drop, permission revocation, deadlock, schema corruption)
// must propagate so operators see the failure instead of getting a
// silently-empty audit trail.

test('snapshot returns empty entries and logs when swarm_memories table is missing', function () {
    Schema::drop('swarm_memories');

    Log::spy();

    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);
    $snapshot = $recorder->snapshot('run-snap', 0);

    expect($snapshot->entries)->toBe([]);

    $row = DB::table('swarm_memory_snapshots')->where('run_id', 'run-snap')->where('step_index', 0)->first();
    expect($row)->not->toBeNull();
    /** @var object $row */
    expect(json_decode((string) $row->payload, true)['entries'])->toBe([]);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'memory table missing')
            && ($context['table'] ?? null) === 'swarm_memories')
        ->once();
});

test('snapshot propagates real QueryException from the memory store instead of swallowing it', function () {
    // Re-bind the recorder against a memory store that throws QueryException
    // from `all()`. We have to rebuild the recorder so it picks up the new
    // store via DefaultSwarmMemory.
    $this->app->instance(MemoryStore::class, new ThrowingMemoryStore);
    $this->app->forgetInstance(SwarmMemory::class);
    $this->app->forgetInstance(SnapshotsMemory::class);

    /** @var SnapshotsMemory $recorder */
    $recorder = $this->app->make(SnapshotsMemory::class);

    expect(fn () => $recorder->snapshot('run-snap', 0))->toThrow(QueryException::class);

    // The snapshot row must not have landed — propagation is the contract.
    expect(DB::table('swarm_memory_snapshots')->where('run_id', 'run-snap')->count())->toBe(0);
});
