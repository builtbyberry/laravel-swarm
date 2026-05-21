<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use Carbon\CarbonImmutable;

/**
 * Immutable value object representing a single Swarm memory entry.
 *
 * An entry is addressed by the tuple `(scope, scope_id, key)`:
 *
 * - `scope`    is one of the {@see MemoryScope} cases.
 * - `scopeId`  is the concrete identifier within that scope (run id,
 *              conversation id, agent class, swarm class).
 * - `key`      is the entry name within the scoped namespace.
 *
 * `value` is plain-data (string, int, float, bool, null, or nested arrays of
 * the same). Stores normalize via `PlainData::value()` before persistence.
 *
 * `metadata` is an associative array of plain-data fields. Reserved for
 * implementation-defined annotations (e.g. capture-policy outcome, source).
 *
 * `createdAt` and `updatedAt` are populated by the store on persist. They are
 * nullable so callers can construct prospective entries before they hit a
 * store.
 */
final readonly class MemoryEntry
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public MemoryScope $scope,
        public string $scopeId,
        public string $key,
        public mixed $value,
        public array $metadata = [],
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $updatedAt = null,
    ) {}

    /**
     * Return a copy of this entry with a new value (and optional metadata).
     *
     * @param  array<string, mixed>|null  $metadata  Replaces metadata when non-null; preserved otherwise.
     */
    public function withValue(mixed $value, ?array $metadata = null): self
    {
        return new self(
            scope: $this->scope,
            scopeId: $this->scopeId,
            key: $this->key,
            value: $value,
            metadata: $metadata ?? $this->metadata,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    /**
     * Return a copy of this entry with persistence timestamps applied.
     *
     * Stores call this after writing the row so the returned entry reflects
     * what was actually persisted.
     */
    public function withTimestamps(CarbonImmutable $createdAt, CarbonImmutable $updatedAt): self
    {
        return new self(
            scope: $this->scope,
            scopeId: $this->scopeId,
            key: $this->key,
            value: $this->value,
            metadata: $this->metadata,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }
}
