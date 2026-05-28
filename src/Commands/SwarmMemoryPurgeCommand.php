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
 * Snapshot rows in `swarm_memory_snapshots` are pruned alongside Run-scoped
 * entries by default — flip with `--keep-snapshots` (or set
 * `SWARM_MEMORY_RETENTION_PRUNE_SNAPSHOTS=false`).
 *
 * `--dry-run` reports per-scope counts without deleting. `--scope=<value>`
 * limits the purge to a single scope.
 *
 * Dispatches a {@see MemoryPurged} event with the per-scope counts and the
 * criteria the operator ran with so app-level audit listeners can capture
 * what was removed (and, in dry-run mode, what would have been removed).
 *
 * Respects `swarm.retention.prevent_prune` the same way `swarm:prune` does:
 * the flag short-circuits destructive deletes but still emits the event with
 * `status=skipped` so audit pipelines see every scheduled run.
 */
#[AsCommand(name: 'swarm:memory:purge')]
class SwarmMemoryPurgeCommand extends Command
{
    protected $signature = 'swarm:memory:purge
        {--dry-run : Report rows that would be pruned without deleting}
        {--scope= : Limit purge to a single scope (run|conversation|agent|swarm)}
        {--keep-snapshots : Skip the swarm_memory_snapshots cascade for Run-scoped purges}';

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
        $pruneSnapshots = ! $keepSnapshots
            && (bool) $config->get('swarm.memory.retention.prune_snapshots', true)
            && $schema->hasTable($snapshotsTable);

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
                dryRun: false,
                cutoffs: $cutoffs,
            );

            $counts = $this->zeroedCounts($retentionDays, $pruneSnapshots);

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

        foreach ($retentionDays as $scopeValue => $days) {
            if ($days === null) {
                continue;
            }

            $cutoff = $cutoffs[$scopeValue];

            if ($dryRun) {
                $counts[$scopeValue] = $this->countScope($connection, $memoriesTable, $scopeValue, $cutoff);

                if ($pruneSnapshots && $scopeValue === MemoryScope::Run->value) {
                    $snapshotsDeleted += $this->countSnapshotsForCutoff(
                        $connection,
                        $memoriesTable,
                        $snapshotsTable,
                        $cutoff,
                    );
                }

                continue;
            }

            if ($pruneSnapshots && $scopeValue === MemoryScope::Run->value) {
                $snapshotsDeleted += $this->deleteSnapshotsForCutoff(
                    $connection,
                    $memoriesTable,
                    $snapshotsTable,
                    $cutoff,
                );
            }

            $counts[$scopeValue] = $this->deleteScope($connection, $memoriesTable, $scopeValue, $cutoff);
        }

        if ($pruneSnapshots) {
            $counts['snapshots'] = $snapshotsDeleted;
        }

        $criteria = $this->criteria(
            retentionDays: $retentionDays,
            scopeFilter: $scopeFilter,
            pruneSnapshots: $pruneSnapshots,
            dryRun: $dryRun,
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

        $this->renderSummary($counts, $retentionDays, $dryRun, $pruneSnapshots);

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
            $map[$scope->value] = is_int($raw) && $raw >= 1 ? $raw : null;
        }

        return $map;
    }

    /**
     * @param  array<string, int|null>  $retentionDays
     * @return array<string, string>
     */
    protected function resolveCutoffs(array $retentionDays, CarbonImmutable $now): array
    {
        $cutoffs = [];

        foreach ($retentionDays as $scope => $days) {
            if ($days === null) {
                continue;
            }

            $cutoffs[$scope] = $now->subDays($days)->toIso8601String();
        }

        return $cutoffs;
    }

    /**
     * @param  array<string, int|null>  $retentionDays
     * @return array<string, int>
     */
    protected function zeroedCounts(array $retentionDays, bool $pruneSnapshots): array
    {
        $counts = [];

        foreach ($retentionDays as $scope => $_days) {
            $counts[$scope] = 0;
        }

        if ($pruneSnapshots) {
            $counts['snapshots'] = 0;
        }

        return $counts;
    }

    /**
     * @param  array<string, int|null>  $retentionDays
     * @param  array<string, string>  $cutoffs
     * @return array{
     *     retention_days: array<string, int|null>,
     *     scope_filter: string|null,
     *     prune_snapshots: bool,
     *     dry_run: bool,
     *     cutoffs: array<string, string>,
     * }
     */
    protected function criteria(
        array $retentionDays,
        ?string $scopeFilter,
        bool $pruneSnapshots,
        bool $dryRun,
        array $cutoffs,
    ): array {
        return [
            'retention_days' => $retentionDays,
            'scope_filter' => $scopeFilter,
            'prune_snapshots' => $pruneSnapshots,
            'dry_run' => $dryRun,
            'cutoffs' => $cutoffs,
        ];
    }

    protected function scopedQuery(Connection $connection, string $table, string $scope, string $cutoff): Builder
    {
        return $connection->table($table)
            ->where('scope', $scope)
            ->where('created_at', '<', $cutoff);
    }

    protected function countScope(Connection $connection, string $table, string $scope, string $cutoff): int
    {
        return (int) $this->scopedQuery($connection, $table, $scope, $cutoff)->count();
    }

    protected function deleteScope(Connection $connection, string $table, string $scope, string $cutoff): int
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
        }
    }

    /**
     * Snapshot rows whose owning Run-scoped memory will be (or has been)
     * purged. Resolves the set via the same `created_at` cutoff applied to
     * `swarm_memories` so the dry-run counts and post-delete counts agree.
     */
    protected function snapshotQuery(
        Connection $connection,
        string $memoriesTable,
        string $snapshotsTable,
        string $cutoff,
    ): Builder {
        return $connection->table($snapshotsTable)
            ->whereIn('run_id', function ($subquery) use ($memoriesTable, $cutoff): void {
                $subquery->from($memoriesTable)
                    ->select('run_id')
                    ->where('scope', MemoryScope::Run->value)
                    ->whereNotNull('run_id')
                    ->where('created_at', '<', $cutoff);
            });
    }

    protected function countSnapshotsForCutoff(
        Connection $connection,
        string $memoriesTable,
        string $snapshotsTable,
        string $cutoff,
    ): int {
        return (int) $this->snapshotQuery($connection, $memoriesTable, $snapshotsTable, $cutoff)->count();
    }

    protected function deleteSnapshotsForCutoff(
        Connection $connection,
        string $memoriesTable,
        string $snapshotsTable,
        string $cutoff,
    ): int {
        $deleted = 0;

        while (true) {
            $ids = $this->snapshotQuery($connection, $memoriesTable, $snapshotsTable, $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return $deleted;
            }

            $deleted += (int) $connection->table($snapshotsTable)
                ->whereIn('id', $ids)
                ->delete();
        }
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int|null>  $retentionDays
     */
    protected function renderSummary(array $counts, array $retentionDays, bool $dryRun, bool $pruneSnapshots): void
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
    }
}
