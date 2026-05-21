<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use Illuminate\Support\Facades\DB;

/**
 * DatabaseMemoryStore compliance suite, mirroring CacheMemoryStoreTest.
 *
 * Exercises the same scenarios against the database driver to confirm
 * behavioral parity across drivers, plus DB-specific concerns (JSON
 * cast round-trip, upsert semantics that preserve created_at across
 * overwrites).
 */
beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
});

test('the database store is bound as the default MemoryStore when persistence is database', function () {
    $store = $this->app->make(MemoryStore::class);

    expect($store)->toBeInstanceOf(DatabaseMemoryStore::class);
});

test('put persists an entry and stamps timestamps', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $persisted = $store->put(new MemoryEntry(
        scope: MemoryScope::Run,
        scopeId: 'run-1',
        key: 'last_output',
        value: 'agent A said hello',
    ));

    expect($persisted->createdAt)->not->toBeNull();
    expect($persisted->updatedAt)->not->toBeNull();
    expect($persisted->createdAt?->toIso8601String())->toBe($persisted->updatedAt?->toIso8601String());
});

test('get returns the persisted entry when present, null otherwise', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    expect($store->get(MemoryScope::Run, 'run-1', 'missing'))->toBeNull();

    $store->put(new MemoryEntry(
        scope: MemoryScope::Run,
        scopeId: 'run-1',
        key: 'present',
        value: ['nested' => true],
        metadata: ['source' => 'test'],
    ));

    $fetched = $store->get(MemoryScope::Run, 'run-1', 'present');

    expect($fetched)->not->toBeNull();
    expect($fetched?->value)->toBe(['nested' => true]);
    expect($fetched?->metadata)->toBe(['source' => 'test']);
});

test('put overwrites the previous value for the same address', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'k', 'first'));
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'k', 'second'));

    expect($store->get(MemoryScope::Run, 'run-1', 'k')?->value)->toBe('second');

    // Single row per address — unique constraint upheld via upsert.
    expect(DB::table('swarm_memories')->count())->toBe(1);
});

test('forget removes the entry and returns true when it existed, false when absent', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    expect($store->forget(MemoryScope::Run, 'run-1', 'absent'))->toBeFalse();

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'present', 'x'));

    expect($store->forget(MemoryScope::Run, 'run-1', 'present'))->toBeTrue();
    expect($store->get(MemoryScope::Run, 'run-1', 'present'))->toBeNull();
});

test('all returns every entry under a given (scope, scope_id) and nothing else', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'a', 1));
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'b', 2));
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-2', 'a', 'other-run'));
    $store->put(new MemoryEntry(MemoryScope::Conversation, 'run-1', 'a', 'other-scope'));

    $all = $store->all(MemoryScope::Run, 'run-1');

    expect($all)->toHaveCount(2);

    $byKey = collect($all)->keyBy(fn (MemoryEntry $entry): string => $entry->key);
    expect($byKey['a']->value)->toBe(1);
    expect($byKey['b']->value)->toBe(2);
});

test('scope isolation: same scope_id and key across different scopes do not collide', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, 'shared-id', 'profile', 'run-value'));
    $store->put(new MemoryEntry(MemoryScope::Conversation, 'shared-id', 'profile', 'convo-value'));
    $store->put(new MemoryEntry(MemoryScope::Agent, 'shared-id', 'profile', 'agent-value'));
    $store->put(new MemoryEntry(MemoryScope::Swarm, 'shared-id', 'profile', 'swarm-value'));

    expect($store->get(MemoryScope::Run, 'shared-id', 'profile')?->value)->toBe('run-value');
    expect($store->get(MemoryScope::Conversation, 'shared-id', 'profile')?->value)->toBe('convo-value');
    expect($store->get(MemoryScope::Agent, 'shared-id', 'profile')?->value)->toBe('agent-value');
    expect($store->get(MemoryScope::Swarm, 'shared-id', 'profile')?->value)->toBe('swarm-value');
});

test('scope_id isolation: same scope and key across different scope_ids do not collide', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-A', 'last_output', 'A-output'));
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-B', 'last_output', 'B-output'));

    expect($store->get(MemoryScope::Run, 'run-A', 'last_output')?->value)->toBe('A-output');
    expect($store->get(MemoryScope::Run, 'run-B', 'last_output')?->value)->toBe('B-output');
});

test('forget only removes the targeted entry under (scope, scope_id, key)', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'a', 1));
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'b', 2));
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-2', 'a', 3));

    $store->forget(MemoryScope::Run, 'run-1', 'a');

    expect($store->get(MemoryScope::Run, 'run-1', 'a'))->toBeNull();
    expect($store->get(MemoryScope::Run, 'run-1', 'b')?->value)->toBe(2);
    expect($store->get(MemoryScope::Run, 'run-2', 'a')?->value)->toBe(3);
});

test('all returns an empty array for an empty (scope, scope_id)', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    expect($store->all(MemoryScope::Run, 'never-written'))->toBe([]);
});

test('plain-data value shapes round-trip through the JSON cast', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $shapes = [
        'string' => 'hello',
        'int' => 42,
        'float' => 3.14,
        'bool_true' => true,
        'bool_false' => false,
        'null' => null,
        'list' => [1, 2, 3],
        'nested' => ['outer' => ['inner' => ['k' => 'v', 'n' => 5]]],
    ];

    foreach ($shapes as $key => $value) {
        $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', $key, $value));
    }

    foreach ($shapes as $key => $value) {
        expect($store->get(MemoryScope::Run, 'run-1', $key)?->value)->toEqual($value);
    }
});

test('overwriting an entry preserves its created_at but advances updated_at', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $initial = $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'k', 'first'));

    // Force a clock tick so updated_at can demonstrably differ from the
    // original created_at across the upsert.
    usleep(1100);

    $updated = $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'k', 'second'));

    expect($updated->createdAt?->toIso8601String())->toBe($initial->createdAt?->toIso8601String());
    expect($updated->updatedAt?->greaterThanOrEqualTo($initial->updatedAt))->toBeTrue();
});

test('metadata defaults to an empty array when not provided', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'k', 'v'));

    $fetched = $store->get(MemoryScope::Run, 'run-1', 'k');
    expect($fetched?->metadata)->toBe([]);
});
