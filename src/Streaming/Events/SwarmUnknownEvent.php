<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

/**
 * Sentinel for persisted event types not recognized by this package version.
 *
 * {@see SwarmStreamEvent::fromArray()} returns this instead of throwing when
 * the stored type string has no registered arm. This keeps deserialization
 * non-fatal across rolling upgrades: a worker on an older package version may
 * encounter events written by a newer version that added infrastructure types
 * (e.g. a future barrier variant) to the hot log.
 *
 * Callers that iterate over events ({@see DatabaseStreamEventStore},
 * {@see TieredStreamEventStore}, {@see DatabaseColdArchiveDriver}) silently
 * skip these sentinels rather than propagating them to the fold layer or
 * stream consumers.
 *
 * @internal
 */
final class SwarmUnknownEvent extends SwarmStreamEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
