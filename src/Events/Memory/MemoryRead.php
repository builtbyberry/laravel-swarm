<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\CacheMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;

/**
 * Fired after a memory entry is read from a {@see MemoryStore}.
 *
 * Dispatched by the bundled store implementations
 * ({@see CacheMemoryStore} and
 * {@see DatabaseMemoryStore}) on every
 * `get()` call — regardless of whether the entry was present. Listeners can
 * hook in for app-level audit trails (e.g. PII access logging), custom
 * metrics, or security monitoring.
 *
 * No value payload is exposed on the event. Subscribers needing the value
 * should re-read through the store under their own access controls; this
 * keeps the event surface compatible with redaction policy in v0.10.
 *
 * Companion / third-party {@see MemoryStore}
 * drivers are expected to dispatch this event from their own `get()`
 * implementations to keep the listener contract uniform across drivers. See
 * the dispatch-layer note on {@see DefaultSwarmMemory}
 * for the rationale.
 */
final class MemoryRead
{
    public function __construct(
        public readonly MemoryScope $scope,
        public readonly string $scopeId,
        public readonly string $key,
    ) {}
}
