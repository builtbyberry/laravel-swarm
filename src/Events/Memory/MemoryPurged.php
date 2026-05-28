<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events\Memory;

use BuiltByBerry\LaravelSwarm\Commands\SwarmMemoryPurgeCommand;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

/**
 * Fired after a retention-driven memory purge run completes.
 *
 * Dispatched once per `swarm:memory:purge` invocation by
 * {@see SwarmMemoryPurgeCommand}, carrying the per-scope deletion counts and the
 * criteria the operator ran with so app-level audit listeners can record what
 * was removed (or, in dry-run mode, what would have been removed) before the
 * rows are gone.
 *
 * Payload shape:
 *
 * - `counts` — associative array of `scope` value (`run`, `conversation`,
 *   `agent`, `swarm`) to the number of entries deleted in that scope, plus
 *   the synthetic `snapshots` key with the number of snapshot rows pruned
 *   alongside their owning Run-scoped entries (zero when snapshot cascade is
 *   disabled or no Run-scoped rows matched). Scopes the operator did not run
 *   against (`--scope=...` filter) are omitted entirely.
 *
 * - `criteria` — frozen description of the run:
 *     - `retention_days` — associative `scope` => `int|null` map of effective
 *        retention windows applied for this run. `null` means the scope has
 *        no configured retention and was therefore not touched.
 *     - `scope_filter`   — the `--scope=<value>` flag value, or `null` when the
 *        operator purged across all configured scopes.
 *     - `prune_snapshots`— whether snapshot rows were pruned alongside
 *        Run-scoped entries (defaults to `true`; flip with the
 *        `--keep-snapshots` flag).
 *     - `dry_run`        — `true` when the operator passed `--dry-run` (in
 *        which case `counts` reports what *would* have been removed and no
 *        rows were actually deleted).
 *     - `cutoffs`        — associative `scope` => ISO-8601 timestamp map of
 *        the `created_at` threshold used for each scope. Omitted scopes
 *        either had no retention configured or were filtered out.
 *
 * Listeners doing audit-trail capture will typically want to filter on
 * `criteria.dry_run === false` to avoid recording preview runs as deletion
 * evidence.
 *
 * Companion / third-party {@see MemoryStore}
 * drivers that ship their own retention command should dispatch this event
 * from their purge implementation to keep the listener contract uniform
 * across drivers.
 */
final class MemoryPurged
{
    /**
     * @param  array<string, int>  $counts  scope value (and `snapshots`) keyed deletion counts
     * @param  array{
     *     retention_days: array<string, int|null>,
     *     scope_filter: string|null,
     *     prune_snapshots: bool,
     *     dry_run: bool,
     *     cutoffs: array<string, string>,
     * }  $criteria
     */
    public function __construct(
        public readonly array $counts,
        public readonly array $criteria,
    ) {}

    /**
     * Total number of memory entries removed (or that would have been removed
     * in dry-run mode), excluding the snapshot cascade.
     */
    public function totalEntries(): int
    {
        $sum = 0;

        foreach (MemoryScope::cases() as $scope) {
            $sum += $this->counts[$scope->value] ?? 0;
        }

        return $sum;
    }

    /**
     * Number of `swarm_memory_snapshots` rows removed alongside their owning
     * Run-scoped memories.
     */
    public function totalSnapshots(): int
    {
        return $this->counts['snapshots'] ?? 0;
    }
}
