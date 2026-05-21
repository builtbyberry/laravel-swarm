<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use Illuminate\Database\QueryException;

/**
 * Test double that throws {@see QueryException} from {@see all()} so the
 * snapshot recorder hardening tests can assert that real DB errors propagate
 * up rather than being silently swallowed as empty entries (review F1+F5).
 *
 * The constructor accepts an optional pre-built `QueryException` so callers
 * can control the wrapped driver error / SQL state when that matters; the
 * default uses a plain "connection refused"-shaped message.
 */
final class ThrowingMemoryStore implements MemoryStore
{
    public function __construct(
        private readonly QueryException $exception = new QueryException(
            'testing',
            'select * from "swarm_memories" where "scope" = ? and "scope_id" = ?',
            ['run', 'run-snap'],
            new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'),
        ),
    ) {}

    public function put(MemoryEntry $entry): MemoryEntry
    {
        throw $this->exception;
    }

    public function get(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry
    {
        throw $this->exception;
    }

    public function forget(MemoryScope $scope, string $scopeId, string $key): bool
    {
        throw $this->exception;
    }

    public function all(MemoryScope $scope, string $scopeId): array
    {
        throw $this->exception;
    }
}
