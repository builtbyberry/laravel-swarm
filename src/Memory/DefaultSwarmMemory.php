<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

/**
 * Default {@see SwarmMemory} facade implementation.
 *
 * Thin coordinator over a {@see MemoryStore} driver. The facade trades the
 * driver's entry-shaped surface for a value-shaped one so callers don't have
 * to construct or unpack `MemoryEntry` objects unless they need metadata or
 * timestamps. Cross-cutting concerns layered on top of memory (capture-policy
 * redaction in v0.10, lifecycle events from #115) live alongside this class
 * rather than inside drivers, so every driver — Cache, Database, vector
 * companion, third-party — gets them for free.
 *
 * @internal
 */
final class DefaultSwarmMemory implements SwarmMemory
{
    public function __construct(
        protected MemoryStore $store,
    ) {}

    public function get(MemoryScope $scope, string $scopeId, string $key): mixed
    {
        return $this->store->get($scope, $scopeId, $key)?->value;
    }

    public function entry(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry
    {
        return $this->store->get($scope, $scopeId, $key);
    }

    public function put(
        MemoryScope $scope,
        string $scopeId,
        string $key,
        mixed $value,
        array $metadata = [],
    ): MemoryEntry {
        return $this->store->put(new MemoryEntry(
            scope: $scope,
            scopeId: $scopeId,
            key: $key,
            value: $value,
            metadata: $metadata,
        ));
    }

    public function forget(MemoryScope $scope, string $scopeId, string $key): bool
    {
        return $this->store->forget($scope, $scopeId, $key);
    }

    public function all(MemoryScope $scope, string $scopeId): array
    {
        return $this->store->all($scope, $scopeId);
    }
}
