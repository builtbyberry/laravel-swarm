<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\CacheMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;

/**
 * Fired after a memory entry is successfully persisted by a {@see MemoryStore}.
 *
 * Dispatched by the bundled store implementations
 * ({@see CacheMemoryStore} and
 * {@see DatabaseMemoryStore}) on every
 * successful `put()`. Listeners can hook in for app-level audit trails, custom
 * metrics, security monitoring, or downstream automation.
 *
 * `metadata` carries the entry's metadata array as persisted by the store —
 * the same shape that ends up on the returned {@see MemoryEntry}.
 *
 * Companion / third-party {@see MemoryStore}
 * drivers are expected to dispatch this event from their own `put()`
 * implementations to keep the listener contract uniform across drivers. See
 * the dispatch-layer note on {@see DefaultSwarmMemory}
 * for the rationale.
 */
final class MemoryWritten
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  int|null  $bytes  Optional approximate JSON-encoded byte size of
     *                           the persisted `value` + `metadata` payload, as
     *                           measured at write time by the dispatching
     *                           {@see MemoryStore}. Drivers that do not measure
     *                           the encoded payload (or third-party drivers
     *                           that have not been updated) leave this `null`.
     *                           Treat the value as a sampling input only — it
     *                           is the wire-shape JSON length, not the
     *                           database row footprint.
     */
    public function __construct(
        public readonly MemoryScope $scope,
        public readonly string $scopeId,
        public readonly string $key,
        public readonly array $metadata = [],
        public readonly ?int $bytes = null,
    ) {}
}
