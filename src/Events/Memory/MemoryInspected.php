<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events\Memory;

use BuiltByBerry\LaravelSwarm\Commands\SwarmMemoryInspectCommand;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

/**
 * Fired after a successful `swarm:memory:inspect` invocation.
 *
 * Dispatched once per successful read by {@see SwarmMemoryInspectCommand},
 * carrying the lookup parameters and how many snapshot rows were surfaced so
 * app-level audit listeners can record operator access to frozen memory
 * views.
 *
 * Failed inspections (missing run, invalid options, configuration errors) do
 * NOT dispatch — this event is the evidence of a completed read, not an
 * attempted one.
 *
 * Payload shape:
 *
 * - `runId`         — the `run_id` argument the operator passed.
 * - `stepIndex`     — the `--step=N` filter when expanded, or `null` when the
 *                     operator listed every step recorded for the run.
 * - `scopeFilter`   — the `--scope=...` filter when set, or `null` when the
 *                     operator inspected every {@see MemoryScope}.
 * - `format`        — the resolved output format (`table` or `json`).
 * - `snapshotCount` — the number of snapshot rows surfaced (1 when `--step`
 *                     was passed and matched, the list size otherwise).
 *
 * Listeners doing audit-trail capture will typically pair this event with the
 * `command.memory.inspect` audit category emitted alongside it.
 */
final class MemoryInspected
{
    public function __construct(
        public readonly string $runId,
        public readonly ?int $stepIndex,
        public readonly ?MemoryScope $scopeFilter,
        public readonly string $format,
        public readonly int $snapshotCount,
    ) {}
}
