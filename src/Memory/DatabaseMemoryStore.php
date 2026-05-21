<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryForgotten;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryRead;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Database-backed {@see MemoryStore} implementation.
 *
 * Default driver when `swarm.persistence.driver` is `database`. The
 * `swarm_memories` table schema lands separately as part of #109
 * (`memory-entries-schema`); until that migration runs, this store will error
 * loudly with a missing-table message rather than silently fail.
 *
 * Each row holds one entry, keyed `(scope, scope_id, key)` with a uniqueness
 * constraint on the tuple. `value` and `metadata` are JSON columns to support
 * arbitrary plain-data shapes.
 *
 * @internal
 */
final class DatabaseMemoryStore implements MemoryStore
{
    use InteractsWithJsonColumns;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected Dispatcher $events,
    ) {}

    public function put(MemoryEntry $entry): MemoryEntry
    {
        $now = CarbonImmutable::now('UTC');
        $existing = $this->table()
            ->where('scope', $entry->scope->value)
            ->where('scope_id', $entry->scopeId)
            ->where('key', $entry->key)
            ->first();

        $createdAt = $existing !== null
            ? CarbonImmutable::parse((string) $existing->created_at)
            : ($entry->createdAt ?? $now);

        $this->table()->upsert(
            [[
                'scope' => $entry->scope->value,
                'scope_id' => $entry->scopeId,
                'key' => $entry->key,
                'value' => $this->encodeJson($entry->value),
                'metadata' => $this->encodeJson($entry->metadata),
                'created_at' => $createdAt,
                'updated_at' => $now,
            ]],
            ['scope', 'scope_id', 'key'],
            ['value', 'metadata', 'updated_at'],
        );

        $persisted = $entry->withTimestamps($createdAt, $now);

        $this->events->dispatch(new MemoryWritten(
            scope: $persisted->scope,
            scopeId: $persisted->scopeId,
            key: $persisted->key,
            metadata: $persisted->metadata,
        ));

        return $persisted;
    }

    public function get(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry
    {
        /** @var object|null $record */
        $record = $this->table()
            ->where('scope', $scope->value)
            ->where('scope_id', $scopeId)
            ->where('key', $key)
            ->first();

        $this->events->dispatch(new MemoryRead(
            scope: $scope,
            scopeId: $scopeId,
            key: $key,
        ));

        return $record === null ? null : $this->hydrate($record);
    }

    public function forget(MemoryScope $scope, string $scopeId, string $key): bool
    {
        $deleted = $this->table()
            ->where('scope', $scope->value)
            ->where('scope_id', $scopeId)
            ->where('key', $key)
            ->delete();

        $this->events->dispatch(new MemoryForgotten(
            scope: $scope,
            scopeId: $scopeId,
            key: $key,
        ));

        return $deleted > 0;
    }

    public function all(MemoryScope $scope, string $scopeId): array
    {
        return $this->table()
            ->where('scope', $scope->value)
            ->where('scope_id', $scopeId)
            ->get()
            ->map(fn (object $record): MemoryEntry => $this->hydrate($record))
            ->all();
    }

    protected function table(): Builder
    {
        return $this->connection->table(
            (string) $this->config->get('swarm.tables.memories', 'swarm_memories'),
        );
    }

    protected function hydrate(object $record): MemoryEntry
    {
        /** @var array<string, mixed> $metadata */
        $metadata = $this->decodeJson($record->metadata, []);

        return new MemoryEntry(
            scope: MemoryScope::from((string) $record->scope),
            scopeId: (string) $record->scope_id,
            key: (string) $record->key,
            value: $this->decodeJson($record->value, null),
            metadata: $metadata,
            createdAt: CarbonImmutable::parse((string) $record->created_at),
            updatedAt: CarbonImmutable::parse((string) $record->updated_at),
        );
    }
}
