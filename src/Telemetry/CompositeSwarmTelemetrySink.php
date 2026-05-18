<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Telemetry;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;

/**
 * Fanout sink for first-party and application telemetry integrations.
 *
 * @internal
 */
final class CompositeSwarmTelemetrySink implements SwarmTelemetrySink
{
    /**
     * @param  array<int, SwarmTelemetrySink>  $sinks
     */
    public function __construct(
        protected array $sinks = [],
    ) {}

    public function add(SwarmTelemetrySink $sink): self
    {
        $this->sinks[] = $sink;

        return $this;
    }

    public function emit(string $category, array $payload): void
    {
        foreach ($this->sinks as $sink) {
            $sink->emit($category, $payload);
        }
    }

    /**
     * @return array<int, SwarmTelemetrySink>
     */
    public function sinks(): array
    {
        return $this->sinks;
    }
}
