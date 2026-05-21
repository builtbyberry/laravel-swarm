<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use Carbon\CarbonImmutable;

/**
 * Lightweight test double for {@see MemoryStore} that keeps entries in a
 * process-local array. Used by snapshot tests so they can populate a
 * deterministic memory view without depending on either the database or
 * cache driver bindings.
 */
final class InMemoryMemoryStore implements MemoryStore
{
    /** @var array<string, array<int, MemoryEntry>> */
    private array $entries = [];

    public function put(MemoryEntry $entry): MemoryEntry
    {
        $now = CarbonImmutable::now('UTC');
        $createdAt = $entry->createdAt ?? $now;
        $persisted = $entry->withTimestamps($createdAt, $now);

        $key = $this->bucket($entry->scope, $entry->scopeId);
        $existing = $this->entries[$key] ?? [];
        $replaced = false;

        foreach ($existing as $i => $candidate) {
            if ($candidate->key === $entry->key) {
                $existing[$i] = $persisted;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            $existing[] = $persisted;
        }

        $this->entries[$key] = $existing;

        return $persisted;
    }

    public function get(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry
    {
        foreach ($this->entries[$this->bucket($scope, $scopeId)] ?? [] as $candidate) {
            if ($candidate->key === $key) {
                return $candidate;
            }
        }

        return null;
    }

    public function forget(MemoryScope $scope, string $scopeId, string $key): bool
    {
        $bucketKey = $this->bucket($scope, $scopeId);
        $existing = $this->entries[$bucketKey] ?? [];

        foreach ($existing as $i => $candidate) {
            if ($candidate->key === $key) {
                unset($existing[$i]);
                $this->entries[$bucketKey] = array_values($existing);

                return true;
            }
        }

        return false;
    }

    public function all(MemoryScope $scope, string $scopeId): array
    {
        return array_values($this->entries[$this->bucket($scope, $scopeId)] ?? []);
    }

    private function bucket(MemoryScope $scope, string $scopeId): string
    {
        return $scope->value.':'.$scopeId;
    }
}
