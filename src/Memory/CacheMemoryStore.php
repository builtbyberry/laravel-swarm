<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryForgotten;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryRead;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\ResolvesSwarmCacheStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Cache-backed {@see MemoryStore} implementation.
 *
 * Default driver when `swarm.persistence.driver` is `cache`. Best for
 * development, testing, and ephemeral workloads — production deployments
 * needing audit-grade durability should run the `database` driver instead.
 *
 * Entries are keyed `swarm:memory:{scope}:{scope_id}:{key}` and live for
 * `swarm.memory.ttl_seconds` (default 24h). A sibling index entry at
 * `swarm:memory:{scope}:{scope_id}:__index` tracks the key set under each
 * `(scope, scope_id)` so {@see all()} can enumerate without driver-specific
 * listing primitives.
 *
 * @internal
 */
final class CacheMemoryStore implements MemoryStore
{
    use ResolvesSwarmCacheStore;

    public function __construct(
        protected CacheFactory $cacheFactory,
        protected ConfigRepository $config,
        protected Dispatcher $events,
    ) {}

    public function put(MemoryEntry $entry): MemoryEntry
    {
        $now = CarbonImmutable::now('UTC');
        $createdAt = $entry->createdAt ?? $now;
        $persisted = $entry->withTimestamps($createdAt, $now);

        $this->store()->put(
            $this->entryKey($entry->scope, $entry->scopeId, $entry->key),
            $this->encode($persisted),
            $this->ttl(),
        );

        $this->appendToIndex($entry->scope, $entry->scopeId, $entry->key);

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
        /** @var array<string, mixed>|null $payload */
        $payload = $this->store()->get($this->entryKey($scope, $scopeId, $key));

        $this->events->dispatch(new MemoryRead(
            scope: $scope,
            scopeId: $scopeId,
            key: $key,
        ));

        return $payload === null ? null : $this->decode($payload);
    }

    public function forget(MemoryScope $scope, string $scopeId, string $key): bool
    {
        $existed = $this->store()->has($this->entryKey($scope, $scopeId, $key));

        $this->store()->forget($this->entryKey($scope, $scopeId, $key));
        $this->removeFromIndex($scope, $scopeId, $key);

        $this->events->dispatch(new MemoryForgotten(
            scope: $scope,
            scopeId: $scopeId,
            key: $key,
        ));

        return $existed;
    }

    public function all(MemoryScope $scope, string $scopeId): array
    {
        $entries = [];

        // Bypass get() here so listing does not emit a MemoryRead per entry.
        foreach ($this->index($scope, $scopeId) as $key) {
            /** @var array<string, mixed>|null $payload */
            $payload = $this->store()->get($this->entryKey($scope, $scopeId, $key));

            if ($payload !== null) {
                $entries[] = $this->decode($payload);
            }
        }

        return $entries;
    }

    protected function entryKey(MemoryScope $scope, string $scopeId, string $key): string
    {
        return $this->prefix().$scope->value.':'.$scopeId.':'.$key;
    }

    protected function indexKey(MemoryScope $scope, string $scopeId): string
    {
        return $this->prefix().$scope->value.':'.$scopeId.':__index';
    }

    protected function prefix(): string
    {
        return (string) $this->config->get('swarm.memory.prefix', 'swarm:memory:');
    }

    protected function ttl(): int
    {
        return (int) $this->config->get('swarm.memory.ttl_seconds', 86400);
    }

    /**
     * @return array<int, string>
     */
    protected function index(MemoryScope $scope, string $scopeId): array
    {
        /** @var array<int, string>|null $index */
        $index = $this->store()->get($this->indexKey($scope, $scopeId));

        return $index ?? [];
    }

    protected function appendToIndex(MemoryScope $scope, string $scopeId, string $key): void
    {
        $index = $this->index($scope, $scopeId);

        if (! in_array($key, $index, true)) {
            $index[] = $key;
            $this->store()->put($this->indexKey($scope, $scopeId), $index, $this->ttl());
        }
    }

    protected function removeFromIndex(MemoryScope $scope, string $scopeId, string $key): void
    {
        $index = array_values(array_filter(
            $this->index($scope, $scopeId),
            static fn (string $candidate): bool => $candidate !== $key,
        ));

        if ($index === []) {
            $this->store()->forget($this->indexKey($scope, $scopeId));

            return;
        }

        $this->store()->put($this->indexKey($scope, $scopeId), $index, $this->ttl());
    }

    /**
     * @return array<string, mixed>
     */
    protected function encode(MemoryEntry $entry): array
    {
        return [
            'scope' => $entry->scope->value,
            'scope_id' => $entry->scopeId,
            'key' => $entry->key,
            'value' => $entry->value,
            'metadata' => $entry->metadata,
            'created_at' => $entry->createdAt?->toIso8601String(),
            'updated_at' => $entry->updatedAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function decode(array $payload): MemoryEntry
    {
        /** @var string $scopeValue */
        $scopeValue = $payload['scope'];
        /** @var string $scopeId */
        $scopeId = $payload['scope_id'];
        /** @var string $key */
        $key = $payload['key'];
        /** @var array<string, mixed> $metadata */
        $metadata = $payload['metadata'] ?? [];
        /** @var string|null $createdAt */
        $createdAt = $payload['created_at'] ?? null;
        /** @var string|null $updatedAt */
        $updatedAt = $payload['updated_at'] ?? null;

        return new MemoryEntry(
            scope: MemoryScope::from($scopeValue),
            scopeId: $scopeId,
            key: $key,
            value: $payload['value'] ?? null,
            metadata: $metadata,
            createdAt: $createdAt !== null ? CarbonImmutable::parse($createdAt) : null,
            updatedAt: $updatedAt !== null ? CarbonImmutable::parse($updatedAt) : null,
        );
    }

    protected function store(): CacheRepository
    {
        return $this->resolveCacheStore($this->cacheFactory, $this->config, 'memory');
    }
}
