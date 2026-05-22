<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryScopeOutOfSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * {@see SwarmMemory} decorator that serves the snapshot-backed memory view
 * during durable replay.
 *
 * Resolves four behaviours by `(scope, scopeId)`:
 *
 * - **Run scope of the replayed run** — reads come from the frozen
 *   {@see MemorySnapshot}; writes and forgets are buffered in-memory and
 *   never touch the wrapped store. The buffer overlays the snapshot for
 *   subsequent reads within the same invocation so an agent that writes-then-
 *   reads-its-own-write sees the buffered value, matching live-store semantics.
 * - **Any other scope** — reads and writes pass through to the wrapped
 *   {@see SwarmMemory} live, with a {@see MemoryScopeOutOfSnapshot} event
 *   dispatched per access so compliance audits can see where determinism
 *   leaked. The snapshot only freezes Run-scope; treating cross-scope
 *   accesses as drift would break agents that read shared Conversation /
 *   Agent / Swarm state during replay.
 *
 * Bound onto `SwarmMemory::class` only for the duration of one agent
 * invocation by the durable runner. Outside replay the regular
 * {@see DefaultSwarmMemory} resolves.
 *
 * @internal
 */
final class ReplaySwarmMemory implements SwarmMemory
{
    /** @var array<string, MemoryEntry> Keyed by `{scope}:{scopeId}:{key}`. */
    private array $writeBuffer = [];

    /** @var array<string, true> Keyed by `{scope}:{scopeId}:{key}`. Tracks forgets. */
    private array $forgetBuffer = [];

    public function __construct(
        private readonly SwarmMemory $live,
        private readonly MemorySnapshot $snapshot,
        private readonly Dispatcher $events,
    ) {}

    public function get(MemoryScope $scope, string $scopeId, string $key): mixed
    {
        return $this->entry($scope, $scopeId, $key)?->value;
    }

    public function entry(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry
    {
        if (! $this->isReplayedRunScope($scope, $scopeId)) {
            $this->dispatchOutOfSnapshot($scope, $scopeId, $key, 'entry');

            return $this->live->entry($scope, $scopeId, $key);
        }

        $bufferKey = $this->bufferKey($scope, $scopeId, $key);

        if (array_key_exists($bufferKey, $this->forgetBuffer)) {
            return null;
        }

        if (array_key_exists($bufferKey, $this->writeBuffer)) {
            return $this->writeBuffer[$bufferKey];
        }

        foreach ($this->snapshot->entries as $row) {
            if ($row['scope'] === $scope->value
                && $row['scope_id'] === $scopeId
                && $row['key'] === $key) {
                return $this->hydrate($row);
            }
        }

        return null;
    }

    public function put(
        MemoryScope $scope,
        string $scopeId,
        string $key,
        mixed $value,
        array $metadata = [],
    ): MemoryEntry {
        if (! $this->isReplayedRunScope($scope, $scopeId)) {
            $this->dispatchOutOfSnapshot($scope, $scopeId, $key, 'put');

            return $this->live->put($scope, $scopeId, $key, $value, $metadata);
        }

        $bufferKey = $this->bufferKey($scope, $scopeId, $key);
        unset($this->forgetBuffer[$bufferKey]);

        $now = CarbonImmutable::now('UTC');
        $existing = $this->writeBuffer[$bufferKey] ?? $this->snapshotEntry($scope, $scopeId, $key);
        $createdAt = $existing !== null ? ($existing->createdAt ?? $now) : $now;

        $entry = new MemoryEntry(
            scope: $scope,
            scopeId: $scopeId,
            key: $key,
            value: $value,
            metadata: $metadata,
            createdAt: $createdAt,
            updatedAt: $now,
        );

        $this->writeBuffer[$bufferKey] = $entry;

        return $entry;
    }

    public function forget(MemoryScope $scope, string $scopeId, string $key): bool
    {
        if (! $this->isReplayedRunScope($scope, $scopeId)) {
            $this->dispatchOutOfSnapshot($scope, $scopeId, $key, 'forget');

            return $this->live->forget($scope, $scopeId, $key);
        }

        $bufferKey = $this->bufferKey($scope, $scopeId, $key);
        $existedInBuffer = array_key_exists($bufferKey, $this->writeBuffer);
        $existedInSnapshot = $this->snapshotEntry($scope, $scopeId, $key) !== null;

        unset($this->writeBuffer[$bufferKey]);
        $this->forgetBuffer[$bufferKey] = true;

        return $existedInBuffer || $existedInSnapshot;
    }

    public function all(MemoryScope $scope, string $scopeId): array
    {
        if (! $this->isReplayedRunScope($scope, $scopeId)) {
            $this->dispatchOutOfSnapshot($scope, $scopeId, '*', 'all');

            return $this->live->all($scope, $scopeId);
        }

        $entries = [];

        foreach ($this->snapshot->entries as $row) {
            if ($row['scope'] !== $scope->value || $row['scope_id'] !== $scopeId) {
                continue;
            }

            $bufferKey = $this->bufferKey($scope, $scopeId, $row['key']);

            if (array_key_exists($bufferKey, $this->forgetBuffer)) {
                continue;
            }

            if (array_key_exists($bufferKey, $this->writeBuffer)) {
                continue; // Surfaced from the buffer below to preserve write-after-read semantics.
            }

            $entries[] = $this->hydrate($row);
        }

        foreach ($this->writeBuffer as $bufferKey => $entry) {
            if ($entry->scope !== $scope || $entry->scopeId !== $scopeId) {
                continue;
            }

            if (array_key_exists($bufferKey, $this->forgetBuffer)) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    private function isReplayedRunScope(MemoryScope $scope, string $scopeId): bool
    {
        return $scope === MemoryScope::Run && $scopeId === $this->snapshot->runId;
    }

    private function bufferKey(MemoryScope $scope, string $scopeId, string $key): string
    {
        return $scope->value.':'.$scopeId.':'.$key;
    }

    private function snapshotEntry(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry
    {
        foreach ($this->snapshot->entries as $row) {
            if ($row['scope'] === $scope->value
                && $row['scope_id'] === $scopeId
                && $row['key'] === $key) {
                return $this->hydrate($row);
            }
        }

        return null;
    }

    /**
     * @param  array{scope: string, scope_id: string, key: string, value: mixed, metadata: array<string, mixed>, created_at: string|null, updated_at: string|null}  $row
     */
    private function hydrate(array $row): MemoryEntry
    {
        return new MemoryEntry(
            scope: MemoryScope::from($row['scope']),
            scopeId: $row['scope_id'],
            key: $row['key'],
            value: $row['value'],
            metadata: $row['metadata'],
            createdAt: $row['created_at'] !== null ? CarbonImmutable::parse($row['created_at']) : null,
            updatedAt: $row['updated_at'] !== null ? CarbonImmutable::parse($row['updated_at']) : null,
        );
    }

    private function dispatchOutOfSnapshot(MemoryScope $scope, string $scopeId, string $key, string $operation): void
    {
        $this->events->dispatch(new MemoryScopeOutOfSnapshot(
            runId: $this->snapshot->runId,
            stepIndex: $this->snapshot->stepIndex,
            scope: $scope,
            scopeId: $scopeId,
            key: $key,
            operation: $operation,
        ));
    }
}
