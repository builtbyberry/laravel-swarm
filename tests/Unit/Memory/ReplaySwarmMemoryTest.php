<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryScopeOutOfSnapshot;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Memory\ReplaySwarmMemory;
use BuiltByBerry\LaravelSwarm\Tests\Support\InMemoryMemoryStore;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

/**
 * Unit tests for the {@see ReplaySwarmMemory} decorator.
 *
 * Verifies the four-quadrant behaviour:
 *
 * 1. Reads against the replayed Run scope come from the frozen snapshot —
 *    not from whatever the live store currently holds.
 * 2. Writes against the replayed Run scope are buffered and never reach the
 *    wrapped store, but are visible to subsequent reads in the same
 *    invocation (write-after-read locality).
 * 3. Reads/writes against any other scope read-through to the live store
 *    and dispatch {@see MemoryScopeOutOfSnapshot} so cross-scope drift is
 *    visible in the audit trail.
 * 4. `forget()` on the replayed Run scope clears the buffered value and
 *    masks the snapshot entry without touching the live store.
 */
function makeSnapshot(string $runId, array $entries = []): MemorySnapshot
{
    $hydrated = [];

    foreach ($entries as [$scope, $scopeId, $key, $value, $metadata]) {
        $hydrated[] = new MemoryEntry(
            scope: $scope,
            scopeId: $scopeId,
            key: $key,
            value: $value,
            metadata: $metadata,
        );
    }

    return MemorySnapshot::fromEntries($runId, 0, $hydrated);
}

function makeReplay(MemorySnapshot $snapshot, ?SwarmMemory $live = null): ReplaySwarmMemory
{
    return new ReplaySwarmMemory(
        live: $live ?? new DefaultSwarmMemory(new InMemoryMemoryStore),
        snapshot: $snapshot,
        events: app(Dispatcher::class),
    );
}

beforeEach(function () {
    // The replay decorator dispatches events through the container; reset the
    // fake on every test so cross-test assertions don't leak.
    Event::fake([MemoryScopeOutOfSnapshot::class]);
});

test('reads against the replayed Run scope return the frozen snapshot value, not the live store', function () {
    $live = new DefaultSwarmMemory(new InMemoryMemoryStore);
    $live->put(MemoryScope::Run, 'run-1', 'config', 'drifted-since-original');

    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'config', 'frozen-at-invocation', []],
    ]);

    $replay = makeReplay($snapshot, $live);

    expect($replay->get(MemoryScope::Run, 'run-1', 'config'))->toBe('frozen-at-invocation');
});

test('entry on the replayed Run scope returns a MemoryEntry hydrated from the snapshot row', function () {
    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'k', 'v', ['source' => 'frozen']],
    ]);

    $entry = makeReplay($snapshot)->entry(MemoryScope::Run, 'run-1', 'k');

    expect($entry)->toBeInstanceOf(MemoryEntry::class);
    expect($entry?->value)->toBe('v');
    expect($entry?->metadata)->toBe(['source' => 'frozen']);
    expect($entry?->scope)->toBe(MemoryScope::Run);
});

test('reads for keys missing from the snapshot return null without touching the live store', function () {
    $live = new DefaultSwarmMemory(new InMemoryMemoryStore);
    $live->put(MemoryScope::Run, 'run-1', 'leaked', 'live-value');

    $snapshot = makeSnapshot('run-1'); // No entries.

    expect(makeReplay($snapshot, $live)->get(MemoryScope::Run, 'run-1', 'leaked'))->toBeNull();
});

test('writes against the replayed Run scope buffer in memory and never reach the live store', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    $snapshot = makeSnapshot('run-1');
    $replay = makeReplay($snapshot, $live);

    $replay->put(MemoryScope::Run, 'run-1', 'scratchpad', 'buffered');

    expect($store->all(MemoryScope::Run, 'run-1'))->toBeEmpty();
});

test('a buffered write is visible to subsequent reads in the same invocation', function () {
    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'snapshot-only', 'original', []],
    ]);
    $replay = makeReplay($snapshot);

    $replay->put(MemoryScope::Run, 'run-1', 'scratchpad', 'just-written');

    expect($replay->get(MemoryScope::Run, 'run-1', 'scratchpad'))->toBe('just-written');
    expect($replay->get(MemoryScope::Run, 'run-1', 'snapshot-only'))->toBe('original');
});

test('a buffered write that overlays a snapshot key returns the buffer on subsequent reads', function () {
    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'k', 'snapshot-value', []],
    ]);
    $replay = makeReplay($snapshot);

    $replay->put(MemoryScope::Run, 'run-1', 'k', 'overlay-value');

    expect($replay->get(MemoryScope::Run, 'run-1', 'k'))->toBe('overlay-value');
});

test('forget on a replayed Run-scope key masks the snapshot row and leaves the live store untouched', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    $live->put(MemoryScope::Run, 'run-1', 'k', 'live-value');

    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'k', 'frozen-value', []],
    ]);

    $replay = makeReplay($snapshot, $live);

    expect($replay->forget(MemoryScope::Run, 'run-1', 'k'))->toBeTrue();
    expect($replay->get(MemoryScope::Run, 'run-1', 'k'))->toBeNull();
    expect($store->get(MemoryScope::Run, 'run-1', 'k')?->value)->toBe('live-value');
});

test('forget returns false when neither the snapshot nor the buffer holds the key', function () {
    $snapshot = makeSnapshot('run-1');

    expect(makeReplay($snapshot)->forget(MemoryScope::Run, 'run-1', 'missing'))->toBeFalse();
});

test('all on the replayed Run scope merges snapshot entries with buffered writes and excludes forgets', function () {
    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'a', '1', []],
        [MemoryScope::Run, 'run-1', 'b', '2', []],
        [MemoryScope::Run, 'run-1', 'c', '3', []],
    ]);

    $replay = makeReplay($snapshot);
    $replay->put(MemoryScope::Run, 'run-1', 'b', 'overlay-b'); // overlay
    $replay->put(MemoryScope::Run, 'run-1', 'd', '4');          // new
    $replay->forget(MemoryScope::Run, 'run-1', 'c');            // mask

    $values = collect($replay->all(MemoryScope::Run, 'run-1'))
        ->mapWithKeys(fn (MemoryEntry $entry) => [$entry->key => $entry->value])
        ->all();

    expect($values)->toBe([
        'a' => '1',
        'b' => 'overlay-b',
        'd' => '4',
    ]);
});

test('reads against non-Run scopes read-through to the live store and dispatch MemoryScopeOutOfSnapshot', function () {
    $live = new DefaultSwarmMemory(new InMemoryMemoryStore);
    $live->put(MemoryScope::Conversation, 'conv-1', 'tone', 'casual');

    $snapshot = makeSnapshot('run-1');

    expect(makeReplay($snapshot, $live)->get(MemoryScope::Conversation, 'conv-1', 'tone'))->toBe('casual');

    Event::assertDispatched(MemoryScopeOutOfSnapshot::class, fn (MemoryScopeOutOfSnapshot $e): bool => $e->runId === 'run-1'
        && $e->scope === MemoryScope::Conversation
        && $e->scopeId === 'conv-1'
        && $e->key === 'tone'
        && $e->operation === 'entry');
});

test('writes against non-Run scopes hit the live store and dispatch MemoryScopeOutOfSnapshot with operation=put', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    $snapshot = makeSnapshot('run-1');

    makeReplay($snapshot, $live)->put(MemoryScope::Agent, 'App\\Agents\\Tagger', 'preference', 'tagged');

    expect($store->get(MemoryScope::Agent, 'App\\Agents\\Tagger', 'preference')?->value)->toBe('tagged');

    Event::assertDispatched(MemoryScopeOutOfSnapshot::class, fn (MemoryScopeOutOfSnapshot $e): bool => $e->scope === MemoryScope::Agent
        && $e->operation === 'put');
});

test('reads against the Run scope of a DIFFERENT run dispatch the cross-scope event and read live', function () {
    $live = new DefaultSwarmMemory(new InMemoryMemoryStore);
    $live->put(MemoryScope::Run, 'run-other', 'k', 'other-run-value');

    $snapshot = makeSnapshot('run-1');

    expect(makeReplay($snapshot, $live)->get(MemoryScope::Run, 'run-other', 'k'))->toBe('other-run-value');

    Event::assertDispatched(MemoryScopeOutOfSnapshot::class, fn (MemoryScopeOutOfSnapshot $e): bool => $e->scopeId === 'run-other');
});

test('all against a non-Run scope dispatches MemoryScopeOutOfSnapshot with a wildcard key', function () {
    $live = new DefaultSwarmMemory(new InMemoryMemoryStore);
    $snapshot = makeSnapshot('run-1');

    makeReplay($snapshot, $live)->all(MemoryScope::Swarm, 'App\\Swarms\\MarkasSwarm');

    Event::assertDispatched(MemoryScopeOutOfSnapshot::class, fn (MemoryScopeOutOfSnapshot $e): bool => $e->scope === MemoryScope::Swarm
        && $e->key === '*'
        && $e->operation === 'all');
});

test('snapshot frozen flag round-trips through fromPersisted to true by default', function () {
    $snapshot = MemorySnapshot::fromPersisted(
        ['run_id' => 'r', 'step_index' => 0, 'entries' => []],
        [],
    );

    expect($snapshot->frozen)->toBeTrue();
});

test('snapshot frozen flag round-trips through fromEntries to false by default', function () {
    expect(MemorySnapshot::fromEntries('r', 0, [])->frozen)->toBeFalse();
});

test('withClearedToolCalls returns an unfrozen snapshot with empty toolCalls', function () {
    $snapshot = MemorySnapshot::fromPersisted(
        ['run_id' => 'r', 'step_index' => 0, 'entries' => []],
        [['name' => 't', 'arguments' => [], 'result' => 'r']],
    );

    expect($snapshot->frozen)->toBeTrue();
    expect($snapshot->toolCalls)->toHaveCount(1);

    $cleared = $snapshot->withClearedToolCalls();

    expect($cleared->toolCalls)->toBe([]);
    expect($cleared->frozen)->toBeFalse();
});
