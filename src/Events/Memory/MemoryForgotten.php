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
 * The `$existed` flag distinguishes the two possible outcomes:
 *
 * - `$existed === true` — an entry was present at the address and a row was
 *   actually removed by the store. This is the case audit-trail listeners
 *   typically care about ("show me every Run-scoped deletion in Q3").
 * - `$existed === false` — no entry was present at the address; the
 *   `forget()` call was a no-op probe. The event is still dispatched so the
 *   listener contract stays uniform across calls, but consumers building
 *   compliance / audit reports will usually want to filter these out to
 *   avoid noisy false positives.
 *
 * Listeners doing audit-trail capture should typically filter by
 * `$existed === true` to avoid no-op noise. Monitoring listeners (for
 * example, security probing detection that wants to see callers poking at
 * addresses they have no right to delete) may want to observe both states.
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
        public readonly bool $existed,
    ) {}
}
