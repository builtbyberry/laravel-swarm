<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

/**
 * Annotates the prospective entry returned when a memory write is skipped.
 *
 * The marker exists only on the entry returned by that `put()` call. It is
 * never persisted and is read immediately by the caller that needs to surface
 * the skipped outcome. Its key is internal and may change without notice.
 *
 * @internal
 */
final class MemoryWriteOutcome
{
    public const KEY = 'swarm:write_outcome';

    private const SKIPPED = 'skipped';

    /** Mark a prospective, non-persisted entry as skipped. */
    public static function skipped(MemoryEntry $entry): MemoryEntry
    {
        return $entry->withValue($entry->value, [
            ...$entry->metadata,
            self::KEY => self::SKIPPED,
        ]);
    }

    /** Determine whether the immediately returned entry represents a skipped write. */
    public static function wasSkipped(MemoryEntry $entry): bool
    {
        return ($entry->metadata[self::KEY] ?? null) === self::SKIPPED;
    }
}
