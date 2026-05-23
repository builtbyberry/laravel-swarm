<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;

/**
 * In-memory MemoryStore double for facade-impl tests. Lets us verify the
 * delegation semantics without booting the application.
 */
final class ArrayMemoryStore implements MemoryStore
{
    /**
     * @var array<string, MemoryEntry>
     */
    private array $entries = [];

    public function put(MemoryEntry $entry): MemoryEntry
    {
        $this->entries[$this->key($entry->scope, $entry->scopeId, $entry->key)] = $entry;

        return $entry;
    }

    public function get(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry
    {
        return $this->entries[$this->key($scope, $scopeId, $key)] ?? null;
    }

    public function forget(MemoryScope $scope, string $scopeId, string $key): bool
    {
        $address = $this->key($scope, $scopeId, $key);
        $existed = array_key_exists($address, $this->entries);
        unset($this->entries[$address]);

        return $existed;
    }

    public function all(MemoryScope $scope, string $scopeId): array
    {
        return array_values(array_filter(
            $this->entries,
            fn (MemoryEntry $entry): bool => $entry->scope === $scope && $entry->scopeId === $scopeId,
        ));
    }

    private function key(MemoryScope $scope, string $scopeId, string $key): string
    {
        return $scope->value.':'.$scopeId.':'.$key;
    }
}

test('get returns the value from the underlying store, or null when absent', function () {
    $store = new ArrayMemoryStore;
    $memory = new DefaultSwarmMemory($store);

    expect($memory->get(MemoryScope::Run, 'run-1', 'missing'))->toBeNull();

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'present', 'hello'));

    expect($memory->get(MemoryScope::Run, 'run-1', 'present'))->toBe('hello');
});

test('entry returns the full entry, not just the value', function () {
    $store = new ArrayMemoryStore;
    $memory = new DefaultSwarmMemory($store);

    $store->put(new MemoryEntry(
        scope: MemoryScope::Conversation,
        scopeId: 'conv-1',
        key: 'tone',
        value: 'casual',
        metadata: ['source' => 'classifier'],
    ));

    $entry = $memory->entry(MemoryScope::Conversation, 'conv-1', 'tone');

    expect($entry)->toBeInstanceOf(MemoryEntry::class);
    expect($entry?->value)->toBe('casual');
    expect($entry?->metadata)->toBe(['source' => 'classifier']);
});

test('put wraps the value in a MemoryEntry and delegates to the store', function () {
    $store = new ArrayMemoryStore;
    $memory = new DefaultSwarmMemory($store);

    $persisted = $memory->put(MemoryScope::Run, 'run-1', 'k', 'v', metadata: ['captured' => 'full']);

    expect($persisted)->toBeInstanceOf(MemoryEntry::class);
    expect($persisted->scope)->toBe(MemoryScope::Run);
    expect($persisted->scopeId)->toBe('run-1');
    expect($persisted->key)->toBe('k');
    expect($persisted->value)->toBe('v');
    expect($persisted->metadata)->toBe(['captured' => 'full']);

    expect($store->get(MemoryScope::Run, 'run-1', 'k')?->value)->toBe('v');
});

test('forget delegates to the store and returns its boolean result', function () {
    $store = new ArrayMemoryStore;
    $memory = new DefaultSwarmMemory($store);

    expect($memory->forget(MemoryScope::Run, 'run-1', 'absent'))->toBeFalse();

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'present', 'x'));

    expect($memory->forget(MemoryScope::Run, 'run-1', 'present'))->toBeTrue();
    expect($memory->get(MemoryScope::Run, 'run-1', 'present'))->toBeNull();
});

test('all returns the entry list from the underlying store', function () {
    $store = new ArrayMemoryStore;
    $memory = new DefaultSwarmMemory($store);

    $memory->put(MemoryScope::Run, 'run-1', 'a', 1);
    $memory->put(MemoryScope::Run, 'run-1', 'b', 2);
    $memory->put(MemoryScope::Run, 'run-2', 'a', 3);

    $entries = $memory->all(MemoryScope::Run, 'run-1');

    expect($entries)->toHaveCount(2);
    expect(collect($entries)->pluck('value')->all())->toEqualCanonicalizing([1, 2]);
});
