<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;

/**
 * Test-only telemetry sink that records every emitted payload.
 */
final class RecordingSwarmTelemetrySink implements SwarmTelemetrySink
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

    public function reset(): void
    {
        $this->records = [];
    }
}
