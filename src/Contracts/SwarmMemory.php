<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;

/**
 * Application-facing facade for Swarm memory.
 *
 * Where {@see MemoryStore} is the storage driver (entry-shaped, low-level),
 * `SwarmMemory` is the ergonomic surface application code, runners, and
 * agent tools use. It accepts and returns values directly — the entry shape
 * is an implementation detail except when callers need metadata or
 * timestamps.
 *
 * Resolve via the container (`app(SwarmMemory::class)`) or the
 * `Swarm::memory()` helper.
 *
 * Implementations delegate persistence to a `MemoryStore` driver and apply
 * cross-cutting concerns layered on top (capture policy redaction, lifecycle
 * events, scope validation). The contract intentionally keeps a small
 * surface — scoped propagation and snapshot-on-invocation live on separate
 * contracts so this facade stays usable from any context.
 */
interface SwarmMemory
{
    /**
     * Read the value at `(scope, scopeId, key)`, or null when absent.
     *
     * Returns the value directly. Use {@see entry()} when metadata or
     * timestamps are needed alongside the value.
     */
    public function get(MemoryScope $scope, string $scopeId, string $key): mixed;

    /**
     * Read the full entry at `(scope, scopeId, key)`, or null when absent.
     */
    public function entry(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry;

    /**
     * Write a value at `(scope, scopeId, key)`, inserting or updating.
     *
     * Returns the persisted entry so callers can read back timestamps and
     * any policy-applied metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function put(
        MemoryScope $scope,
        string $scopeId,
        string $key,
        mixed $value,
        array $metadata = [],
    ): MemoryEntry;

    /**
     * Delete the entry at `(scope, scopeId, key)`. Returns true when a row
     * was removed.
     */
    public function forget(MemoryScope $scope, string $scopeId, string $key): bool;

    /**
     * Return every entry under a given `(scope, scopeId)`.
     *
     * Order is not guaranteed. Callers that need a specific order must sort
     * the result themselves.
     *
     * @return array<int, MemoryEntry>
     */
    public function all(MemoryScope $scope, string $scopeId): array;
}
