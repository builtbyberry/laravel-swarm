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
 *   `agent`, `swarm`) to the number of entries deleted in that scope, plus the
 *   synthetic `snapshots` and `checkpoints` keys with the number of
 *   `swarm_memory_snapshots` / `swarm_stream_step_checkpoints` (#202) rows
 *   pruned alongside their owning Run-scoped entries (zero when that cascade is
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
 *     - `prune_checkpoints` — whether `swarm_stream_step_checkpoints` rows were
 *        pruned alongside Run-scoped entries (#202). Shares the
 *        `--keep-snapshots` retention decision with `prune_snapshots`.
 *     - `dry_run`        — `true` when the operator passed `--dry-run` (in
 *        which case `counts` reports what *would* have been removed and no
 *        rows were actually deleted).
 *     - `prevent_prune`  — `true` when `swarm.retention.prevent_prune`
 *        (`SWARM_PREVENT_PRUNE`) suppressed the destructive deletes for this
 *        run. The event still dispatches so scheduled runs stay visible to the
 *        audit pipeline, but `counts` are all zero. This is what distinguishes
 *        a compliance-suppressed run from a run that simply had nothing to
 *        delete — both report zero counts with `dry_run = false`.
 *     - `cutoffs`        — associative `scope` => ISO-8601 timestamp map of
 *        the `created_at` threshold used for each scope. Omitted scopes
 *        either had no retention configured or were filtered out.
 *
 * Listeners doing audit-trail capture will typically want to filter on
 * `criteria.dry_run === false` to avoid recording preview runs as deletion
 * evidence, and inspect `criteria.prevent_prune` to tell a suppressed run
 * apart from a genuine zero-delete run.
 *
 * Companion / third-party {@see MemoryStore}
 * drivers that ship their own retention command should dispatch this event
 * from their purge implementation to keep the listener contract uniform
 * across drivers.
 */
final readonly class MemoryPurged
{
    /**
     * @param  array<string, int>  $counts  scope value (and `snapshots`, `checkpoints`) keyed deletion counts
     * @param  array{
     *     retention_days: array<string, int|null>,
     *     scope_filter: string|null,
     *     prune_snapshots: bool,
     *     prune_checkpoints: bool,
     *     dry_run: bool,
     *     prevent_prune: bool,
     *     cutoffs: array<string, string>,
     * }  $criteria
     */
    public function __construct(
        public array $counts,
        public array $criteria,
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

    /**
     * Number of `swarm_stream_step_checkpoints` rows removed alongside their
     * owning Run-scoped memories (#202).
     */
    public function totalCheckpoints(): int
    {
        return $this->counts['checkpoints'] ?? 0;
    }
}
