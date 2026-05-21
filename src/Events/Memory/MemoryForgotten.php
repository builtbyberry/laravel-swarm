<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\CacheMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;

/**
 * Fired after a memory entry is removed from a {@see MemoryStore}.
 *
 * Dispatched by the bundled store implementations
 * ({@see CacheMemoryStore} and
 * {@see DatabaseMemoryStore}) on every
 * `forget()` call, regardless of whether an entry actually existed at the
 * address. Listeners can hook in for app-level audit trails, custom metrics,
 * or downstream cleanup automation.
 *
 * Companion / third-party {@see MemoryStore}
 * drivers are expected to dispatch this event from their own `forget()`
 * implementations to keep the listener contract uniform across drivers. See
 * the dispatch-layer note on {@see DefaultSwarmMemory}
 * for the rationale.
 */
final class MemoryForgotten
{
    public function __construct(
        public readonly MemoryScope $scope,
        public readonly string $scopeId,
        public readonly string $key,
    ) {}
}
