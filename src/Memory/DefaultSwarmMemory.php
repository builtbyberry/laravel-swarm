<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryForgotten;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryRead;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;

/**
 * Default {@see SwarmMemory} facade implementation.
 *
 * Thin coordinator over a {@see MemoryStore} driver. The facade trades the
 * driver's entry-shaped surface for a value-shaped one so callers don't have
 * to construct or unpack `MemoryEntry` objects unless they need metadata or
 * timestamps.
 *
 * Dispatch-layer decision for memory lifecycle events ({@see MemoryWritten},
 * {@see MemoryRead},
 * {@see MemoryForgotten}): events are
 * dispatched at the {@see MemoryStore} layer, not from this facade. That way
 * callers who resolve a store directly (or use a custom driver outside the
 * facade) still get the lifecycle event stream. The trade-off is that custom
 * {@see MemoryStore} drivers must dispatch the events themselves to be
 * uniform with the bundled Cache + Database stores — there is no automatic
 * wrapping. Capture-policy redaction (v0.10) is the cross-cutting concern
 * that will live here at the facade layer, since redaction needs the
 * pre-store value shape.
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
