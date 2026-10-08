<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

/**
 * Annotates the prospective entry returned when a memory write is skipped.
 *
 * @internal
 */
final class MemoryWriteOutcome
{
    public const KEY = 'swarm:write_outcome';

    private const SKIPPED = 'skipped';

    public static function skipped(MemoryEntry $entry): MemoryEntry
    {
        return $entry->withValue($entry->value, [
            ...$entry->metadata,
            self::KEY => self::SKIPPED,
        ]);
    }

    public static function wasSkipped(MemoryEntry $entry): bool
    {
        return ($entry->metadata[self::KEY] ?? null) === self::SKIPPED;
    }
}
