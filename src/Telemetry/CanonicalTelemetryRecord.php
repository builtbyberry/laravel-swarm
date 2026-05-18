<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Telemetry;

/**
 * Immutable observability record emitted by the swarm runtime.
 *
 * @internal
 */
final readonly class CanonicalTelemetryRecord
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $category,
        public array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function make(string $category, array $payload): self
    {
        return new self($category, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
