<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryPurged;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Enforce per-scope memory retention windows.
 *
 * Reads `swarm.memory.retention.days` (per {@see MemoryScope}) and deletes
 * `swarm_memories` rows whose `created_at` predates the configured cutoff.
 * Snapshot rows in `swarm_memory_snapshots` AND stream-step checkpoint rows in
 * `swarm_stream_step_checkpoints` (#202) are pruned alongside Run-scoped
 * entries by default — both flip with `--keep-snapshots` (or set
 * `SWARM_MEMORY_RETENTION_PRUNE_SNAPSHOTS=false`), since both are per-step run
 * data tied to the same retention decision.
 *
 * `--dry-run` reports per-scope counts without deleting. `--scope=<value>`
 * limits the purge to a single scope.
 *
 * Dispatches a {@see MemoryPurged} event with the per-scope counts and the
 * criteria the operator ran with so app-level audit listeners can capture
 * what was removed (and, in dry-run mode, what would have been removed).
 *
 * Respects `swarm.retention.prevent_prune` the same way `swarm:prune` does:
 * the flag short-circuits destructive deletes but still dispatches the
 * {@see MemoryPurged} event (with `criteria.prevent_prune=true`) and emits the
 * `command.memory.purge` audit entry with `status=skipped`, so audit pipelines
 * see every scheduled run.
 */
#[AsCommand(name: 'swarm:memory:purge')]
class SwarmMemoryPurgeCommand extends Command
{
    protected $signature = 'swarm:memory:purge
        {--dry-run : Report rows that would be pruned without deleting}
        {--scope= : Limit purge to a single scope (run|conversation|agent|swarm)}
        {--keep-snapshots : Skip the swarm_memory_snapshots AND swarm_stream_step_checkpoints early-prune for Run-scoped purges}
        {--pause=0 : Milliseconds to sleep between delete batches; throttles DB/replication load on large tables (default 0 = no pause)}';

    protected $description = 'Enforce configured per-scope memory retention windows (use --dry-run to preview)';

    /**
     * Maximum rows deleted per batch. Bounded to avoid long-running transactions
     * on large memory tables.
     */
    protected const CHUNK_SIZE = 1000;

    public function handle(
        Connection $connection,
        ConfigRepository $config,
        Dispatcher $events,
        SwarmAuditDispatcher $audit,
    ): int {
        $actorMetadata = ['actor' => Actor::system('artisan')->toArray()];

        if ($config->get('swarm.persistence.driver') !== 'database') {
            $this->components->warn(
                'swarm:memory:purge requires the database-backed memory store. '
                .'Set swarm.persistence.driver=database before scheduling retention enforcement.'
            );

            return self::SUCCESS;
        }

        $memoriesTable = (string) $config->get('swarm.tables.memories', 'swarm_memories');
        $snapshotsTable = (string) $config->get('swarm.tables.memory_snapshots', 'swarm_memory_snapshots');
        $checkpointsTable = (string) $config->get('swarm.tables.stream_step_checkpoints', 'swarm_stream_step_checkpoints');

        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable($memoriesTable)) {
            $this->components->warn(
                "Skipping memory purge because the memories table [{$memoriesTable}] does not exist. "
                .'Run the Laravel Swarm migrations before enforcing memory retention.'
            );

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $scopeFilter = $this->resolveScopeFilter();

        if ($scopeFilter === false) {
            return self::FAILURE;
        }

        $keepSnapshots = (bool) $this->option('keep-snapshots');
        $prunePerStepRun = ! $keepSnapshots
            && (bool) $config->get('swarm.memory.retention.prune_snapshots', true);
        $pruneSnapshots = $prunePerStepRun && $schema->hasTable($snapshotsTable);
        // Stream-step checkpoints (#202) share the snapshot retention decision —
        // both are per-step run data the operator opts out of with the same flag.
        $pruneCheckpoints = $prunePerStepRun && $schema->hasTable($checkpointsTable);

        $pauseMs = $this->resolvePauseMs();

        $preventPrune = $config->get('swarm.retention.prevent_prune', false) === true;

        $retentionDays = $this->resolveRetentionDays($config, $scopeFilter);
        $now = CarbonImmutable::now('UTC');
        $cutoffs = $this->resolveCutoffs($retentionDays, $now);

        if ($preventPrune && ! $dryRun) {
            $this->components->warn(
                'Swarm memory purge is disabled because swarm.retention.prevent_prune is true (SWARM_PREVENT_PRUNE). '
                .'Use --dry-run to inspect impact without deleting.'
            );

            $criteria = $this->criteria(
                retentionDays: $retentionDays,
                scopeFilter: $scopeFilter,
                pruneSnapshots: $pruneSnapshots,
                pruneCheckpoints: $pruneCheckpoints,
                dryRun: false,
                preventPrune: true,
                cutoffs: $cutoffs,
            );

            $counts = $this->zeroedCounts($retentionDays, $pruneSnapshots, $pruneCheckpoints);

            $events->dispatch(new MemoryPurged($counts, $criteria));

            $audit->emit('command.memory.purge', [
                'dry_run' => false,
                'prevent_prune' => true,
                'status' => 'skipped',
                'counts' => $counts,
                'criteria' => $criteria,
                ...$audit->metadata($actorMetadata),
            ]);

            return self::SUCCESS;
        }

        $counts = [];
        $snapshotsDeleted = 0;
        $checkpointsDeleted = 0;

        foreach ($retentionDays as $scopeValue => $days) {
            if ($days === null) {
                continue;
            }

            $cutoff = $cutoffs[$scopeValue];
            $isRunScope = $scopeValue === MemoryScope::Run->value;

            if ($dryRun) {
                $counts[$scopeValue] = $this->countScope($connection, $memoriesTable, $scopeValue, $cutoff);

                if ($pruneSnapshots && $isRunScope) {
                    $snapshotsDeleted += $this->countChildForCutoff(
                        $connection,
                        $memoriesTable,
                        $snapshotsTable,
                        $cutoff,
                    );
                }

                if ($pruneCheckpoints && $isRunScope) {
                    $checkpointsDeleted += $this->countChildForCutoff(
                        $connection,
                        $memoriesTable,
                        $checkpointsTable,
                        $cutoff,
                    );
                }

                continue;
            }

            if ($pruneSnapshots && $isRunScope) {
                $snapshotsDeleted += $this->deleteChildForCutoff(
                    $connection,
                    $memoriesTable,
                    $snapshotsTable,
                    $cutoff,
                    $pauseMs,
                );
            }

            if ($pruneCheckpoints && $isRunScope) {
                $checkpointsDeleted += $this->deleteChildForCutoff(
                    $connection,
                    $memoriesTable,
                    $checkpointsTable,
                    $cutoff,
                    $pauseMs,
                );
            }

            $counts[$scopeValue] = $this->deleteScope($connection, $memoriesTable, $scopeValue, $cutoff, $pauseMs);
        }

        if ($pruneSnapshots) {
            $counts['snapshots'] = $snapshotsDeleted;
        }

        if ($pruneCheckpoints) {
            $counts['checkpoints'] = $checkpointsDeleted;
        }

        $criteria = $this->criteria(
            retentionDays: $retentionDays,
            scopeFilter: $scopeFilter,
            pruneSnapshots: $pruneSnapshots,
            pruneCheckpoints: $pruneCheckpoints,
            dryRun: $dryRun,
            preventPrune: false,
            cutoffs: $cutoffs,
        );

        $events->dispatch(new MemoryPurged($counts, $criteria));

        $audit->emit('command.memory.purge', [
            'dry_run' => $dryRun,
            'prevent_prune' => false,
            'status' => $dryRun ? 'dry_run' : 'purged',
            'counts' => $counts,
            'criteria' => $criteria,
            ...$audit->metadata($actorMetadata),
        ]);

        $this->renderSummary($counts, $retentionDays, $dryRun, $pruneSnapshots, $pruneCheckpoints);

        return self::SUCCESS;
    }

    /**
     * Resolve the `--scope=<value>` option. Returns the scope value string
     * for a constrained run, `null` for an unconstrained run across every
     * configured scope, or `false` when the operator passed an unknown scope.
     */
    protected function resolveScopeFilter(): string|null|false
    {
        $raw = $this->option('scope');

        if ($raw === null) {
            return null;
        }

        $rawString = is_string($raw) ? $raw : (is_scalar($raw) ? (string) $raw : '');

        if ($rawString === '') {
            return null;
        }

        $value = strtolower($rawString);

        foreach (MemoryScope::cases() as $scope) {
            if ($scope->value === $value) {
                return $scope->value;
            }
        }

        $valid = implode(', ', array_map(fn (MemoryScope $s): string => $s->value, MemoryScope::cases()));
        $this->components->error("Unknown --scope value [{$rawString}]. Expected one of: {$valid}.");

        return false;
    }

    /**
     * Build the per-scope retention map filtered by the operator's `--scope` flag.
     *
     * `null` disables retention for a scope. The minimum enforceable window is
     * one day: a configured value below 1 (e.g. `0` or a negative) is treated as
     * "no retention" and surfaces a warning, so an operator who set `0` expecting
     * an immediate purge is not silently no-op'd.
     *
     * @return array<string, int|null>
     */
    protected function resolveRetentionDays(ConfigRepository $config, ?string $scopeFilter): array
    {
        $configured = $config->get('swarm.memory.retention.days', []);
        $map = [];

        foreach (MemoryScope::cases() as $scope) {
            if ($scopeFilter !== null && $scope->value !== $scopeFilter) {
                continue;
            }

            $raw = is_array($configured) ? ($configured[$scope->value] ?? null) : null;

            if (is_int($raw) && $raw < 1) {
                $this->components->warn(sprintf(
                    'Ignoring retention window [%d] for scope [%s]: the minimum enforceable window is 1 day. '
                    .'Set null to disable, or a value >= 1 to enforce.',
                    $raw,
                    $scope->value,
                ));
            }

            $map[$scope->value] = is_int($raw) && $raw >= 1 ? $raw : null;
        }

        return $map;
    }

    /**
     * Resolve the per-scope `created_at` cutoff as a {@see CarbonImmutable}.
     *
     * The cutoff is kept as a Carbon instance (not a pre-formatted string) so it
     * is handed to the query builder as a `DateTimeInterface`. The builder then
     * binds it in the connection's native datetime format (`Y-m-d H:i:s`), which
     * matches how `created_at` is stored. Pre-formatting to ISO-8601 here would
     * bind a string like `2026-05-24T00:00:00+00:00` whose `T`/offset shape does
     * not match the stored format, producing wrong comparisons (lexicographic on
     * SQLite, invalid-datetime on MySQL). The ISO-8601 representation is derived
     * only for the audit/event payload — see {@see criteria()}.
     *
     * @param  array<string, int|null>  $retentionDays
     * @return array<string, CarbonImmutable>
     */
    protected function resolveCutoffs(array $retentionDays, CarbonImmutable $now): array
    {
        $cutoffs = [];

        foreach ($retentionDays as $scope => $days) {
            if ($days === null) {
                continue;
            }

            $cutoffs[$scope] = $now->subDays($days);
        }

        return $cutoffs;
    }

    /**
     * @param  array<string, int|null>  $retentionDays
     * @return array<string, int>
     */
    protected function zeroedCounts(array $retentionDays, bool $pruneSnapshots, bool $pruneCheckpoints): array
    {
        $counts = [];

        foreach ($retentionDays as $scope => $_days) {
            $counts[$scope] = 0;
        }

        if ($pruneSnapshots) {
            $counts['snapshots'] = 0;
        }

        if ($pruneCheckpoints) {
            $counts['checkpoints'] = 0;
        }

        return $counts;
    }

    /**
     * @param  array<string, int|null>  $retentionDays
     * @param  array<string, CarbonImmutable>  $cutoffs
     * @return array{
     *     retention_days: array<string, int|null>,
     *     scope_filter: string|null,
     *     prune_snapshots: bool,
     *     prune_checkpoints: bool,
     *     dry_run: bool,
     *     prevent_prune: bool,
     *     cutoffs: array<string, string>,
     * }
     */
    protected function criteria(
        array $retentionDays,
        ?string $scopeFilter,
        bool $pruneSnapshots,
        bool $pruneCheckpoints,
        bool $dryRun,
        bool $preventPrune,
        array $cutoffs,
    ): array {
        return [
            'retention_days' => $retentionDays,
            'scope_filter' => $scopeFilter,
            'prune_snapshots' => $pruneSnapshots,
            'prune_checkpoints' => $pruneCheckpoints,
            'dry_run' => $dryRun,
            'prevent_prune' => $preventPrune,
            'cutoffs' => array_map(
                static fn (CarbonImmutable $cutoff): string => $cutoff->toIso8601String(),
                $cutoffs,
            ),
        ];
    }

    protected function scopedQuery(Connection $connection, string $table, string $scope, CarbonImmutable $cutoff): Builder
    {
        return $connection->table($table)
            ->where('scope', $scope)
            ->where('created_at', '<', $cutoff);
    }

    protected function countScope(Connection $connection, string $table, string $scope, CarbonImmutable $cutoff): int
    {
        return (int) $this->scopedQuery($connection, $table, $scope, $cutoff)->count();
    }

    protected function deleteScope(Connection $connection, string $table, string $scope, CarbonImmutable $cutoff, int $pauseMs = 0): int
    {
        $deleted = 0;

        while (true) {
            // SQLite does not support DELETE with LIMIT; resolve eligible IDs
            // first and delete by primary key in bounded batches. Portable
            // across SQLite, MySQL, and Postgres.
            $ids = $this->scopedQuery($connection, $table, $scope, $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return $deleted;
            }

            $deleted += (int) $connection->table($table)
                ->whereIn('id', $ids)
                ->delete();

            $this->pauseBetweenBatches($pauseMs);
        }
    }

    /**
     * Child rows (`swarm_memory_snapshots` or `swarm_stream_step_checkpoints`)
     * whose owning Run-scoped memory will be (or has been) purged. Resolves the
     * set via the same `created_at` cutoff applied to `swarm_memories` so the
     * dry-run counts and post-delete counts agree.
     *
     * Scope note: this only reaches child rows for runs that wrote a Run-scoped
     * memory row. Child rows for a run with no Run-scoped memory are owned by
     * run-history retention — both `swarm_memory_snapshots.run_id` and
     * `swarm_stream_step_checkpoints.run_id` cascade on delete from
     * `swarm_run_histories`, so `swarm:prune` is the backstop that removes them.
     * This command only prunes them *early* when their memory ages out first.
     */
    protected function childQuery(
        Connection $connection,
        string $memoriesTable,
        string $childTable,
        CarbonImmutable $cutoff,
    ): Builder {
        return $connection->table($childTable)
            ->whereIn('run_id', function ($subquery) use ($memoriesTable, $cutoff): void {
                $subquery->from($memoriesTable)
                    ->select('run_id')
                    ->where('scope', MemoryScope::Run->value)
                    ->whereNotNull('run_id')
                    ->where('created_at', '<', $cutoff);
            });
    }

    protected function countChildForCutoff(
        Connection $connection,
        string $memoriesTable,
        string $childTable,
        CarbonImmutable $cutoff,
    ): int {
        return (int) $this->childQuery($connection, $memoriesTable, $childTable, $cutoff)->count();
    }

    protected function deleteChildForCutoff(
        Connection $connection,
        string $memoriesTable,
        string $childTable,
        CarbonImmutable $cutoff,
        int $pauseMs = 0,
    ): int {
        $deleted = 0;

        while (true) {
            $ids = $this->childQuery($connection, $memoriesTable, $childTable, $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return $deleted;
            }

            $deleted += (int) $connection->table($childTable)
                ->whereIn('id', $ids)
                ->delete();

            $this->pauseBetweenBatches($pauseMs);
        }
    }

    /**
     * Resolve the `--pause=<ms>` throttle. Returns a non-negative millisecond
     * count; an unparsable or negative value is clamped to 0 (no pause).
     */
    protected function resolvePauseMs(): int
    {
        $raw = $this->option('pause');

        if (is_int($raw)) {
            return max(0, $raw);
        }

        if (is_string($raw) && is_numeric($raw)) {
            return max(0, (int) $raw);
        }

        return 0;
    }

    /**
     * Sleep between delete batches when a throttle is configured. Lets an
     * unattended scheduled sweep on a large table shed DB / replication
     * pressure instead of deleting flat-out. A zero pause is a no-op.
     */
    protected function pauseBetweenBatches(int $pauseMs): void
    {
        if ($pauseMs > 0) {
            usleep($pauseMs * 1000);
        }
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int|null>  $retentionDays
     */
    protected function renderSummary(array $counts, array $retentionDays, bool $dryRun, bool $pruneSnapshots, bool $pruneCheckpoints): void
    {
        $verb = $dryRun ? 'Would purge' : 'Purged';
        $configured = false;

        foreach ($retentionDays as $scope => $days) {
            if ($days === null) {
                $this->components->info(sprintf(
                    'Scope [%s] has no retention configured — skipped.',
                    $scope,
                ));

                continue;
            }

            $configured = true;
            $count = $counts[$scope] ?? 0;

            $this->components->info(sprintf(
                '%s %d %s-scoped memory entry%s older than %d day%s.',
                $verb,
                $count,
                $scope,
                $count === 1 ? '' : 's',
                $days,
                $days === 1 ? '' : 's',
            ));
        }

        if (! $configured) {
            $this->components->warn(
                'No memory retention windows configured. Set swarm.memory.retention.days.* '
                .'(SWARM_MEMORY_RETENTION_*_DAYS) before scheduling this command.'
            );
        }

        if ($pruneSnapshots) {
            $snapshotCount = $counts['snapshots'] ?? 0;

            $this->components->info(sprintf(
                '%s %d swarm_memory_snapshots row%s cascading from Run-scoped purges.',
                $verb,
                $snapshotCount,
                $snapshotCount === 1 ? '' : 's',
            ));
        }

        if ($pruneCheckpoints) {
            $checkpointCount = $counts['checkpoints'] ?? 0;

            $this->components->info(sprintf(
                '%s %d swarm_stream_step_checkpoints row%s cascading from Run-scoped purges.',
                $verb,
                $checkpointCount,
                $checkpointCount === 1 ? '' : 's',
            ));
        }
    }
}
