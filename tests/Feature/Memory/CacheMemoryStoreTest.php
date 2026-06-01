<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\CacheMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;
use Illuminate\Contracts\Cache\Factory;

beforeEach(function () {
    /** @var Factory $cacheFactory */
    $cacheFactory = $this->app->make('cache');
    $cacheFactory->store('array')->flush();
});

test('the cache store is bound as the default MemoryStore when persistence is cache', function () {
    $store = $this->app->make(MemoryStore::class);

    // The container wraps the driver in the redaction decorator; the cache
    // driver is the wrapped inner store.
    expect($store)->toBeInstanceOf(RedactingMemoryStore::class);
    $inner = $store instanceof RedactingMemoryStore ? $store->inner() : null;
    expect($inner)->toBeInstanceOf(CacheMemoryStore::class);
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

test('the index is cleaned up when all keys under a (scope, scope_id) are forgotten', function () {
    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'only', 'value'));
    expect($store->all(MemoryScope::Run, 'run-1'))->toHaveCount(1);

    $store->forget(MemoryScope::Run, 'run-1', 'only');
    expect($store->all(MemoryScope::Run, 'run-1'))->toHaveCount(0);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'newly-added', 'value'));
    expect($store->all(MemoryScope::Run, 'run-1'))->toHaveCount(1);
});
