<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;

/**
 * Test-only audit sink that records every emitted evidence payload.
 */
final class RecordingSwarmAuditSink implements SwarmAuditSink
{
    /** @var array<int, array<string, mixed>> */
    private array $records = [];

    public function emit(string $category, array $payload): void
    {
        $this->records[] = $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allRecords(): array
    {
        return $this->records;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recordsForCategory(string $category): array
    {
        return array_values(array_filter(
            $this->records,
            fn (array $record): bool => ($record['category'] ?? '') === $category,
        ));
    }

    public function hasCategory(string $category): bool
    {
        return $this->recordsForCategory($category) !== [];
    }

    public function reset(): void
    {
        $this->records = [];
    }
}
