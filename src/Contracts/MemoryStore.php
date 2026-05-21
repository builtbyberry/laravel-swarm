<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;

/**
 * Persistence driver for Swarm memory entries.
 *
 * Implementations own the storage backend (database, in-memory, vector store
 * via a companion package, etc.). They handle one entry at a time, keyed by
 * the `(scope, scope_id, key)` tuple, and do not interpret scope semantics
 * beyond using it as an indexed dimension.
 *
 * The default binding is `DatabaseMemoryStore`. Companion packages or
 * applications may bind a different implementation in the container to swap
 * backends without changing the calling code.
 *
 * Stores are responsible for plain-data normalization on write and timestamp
 * stamping. They must round-trip `MemoryEntry` instances faithfully: writing
 * an entry and reading it back yields equal scope, scope_id, key, value, and
 * metadata.
 */
interface MemoryStore
{
    /**
     * Persist an entry, inserting or updating by `(scope, scope_id, key)`.
     *
     * Returns the persisted entry with `createdAt` and `updatedAt` populated.
     */
    public function put(MemoryEntry $entry): MemoryEntry;

    /**
     * Fetch a single entry by its address, or null when absent.
     */
    public function get(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry;

    /**
     * Delete an entry by its address. Returns true when a row was removed.
     */
    public function forget(MemoryScope $scope, string $scopeId, string $key): bool;

    /**
     * Return every entry under a given `(scope, scope_id)`.
     *
     * Order is not guaranteed across drivers. Callers that need a specific
     * order must sort the result themselves.
     *
     * @return array<int, MemoryEntry>
     */
    public function all(MemoryScope $scope, string $scopeId): array;
}
