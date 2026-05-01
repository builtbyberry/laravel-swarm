<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:prune')]
class SwarmPruneCommand extends Command
{
    protected $signature = 'swarm:prune {--dry-run : Report rows that would be pruned without deleting}';

    protected $description = 'Prune expired swarm database persistence records in bounded batches (use --dry-run to preview; retention.prevent_prune disables deletes)';

    protected const CHUNK_SIZE = 1000;

    public function handle(Connection $connection, ConfigRepository $config): int
    {
        if ($config->get('swarm.retention.prevent_prune', false) === true && ! $this->option('dry-run')) {
            $this->components->warn('Swarm pruning is disabled because swarm.retention.prevent_prune is true (SWARM_PREVENT_PRUNE). Use --dry-run to inspect impact without deleting.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $tables = [
            'durable' => (string) $config->get('swarm.tables.durable', 'swarm_durable_runs'),
            'history' => (string) $config->get('swarm.tables.history', 'swarm_run_histories'),
            'history_steps' => (string) $config->get('swarm.tables.history_steps', 'swarm_run_steps'),
            'stream_events' => (string) $config->get('swarm.tables.stream_events', 'swarm_stream_events'),
            'contexts' => (string) $config->get('swarm.tables.contexts', 'swarm_contexts'),
            'artifacts' => (string) $config->get('swarm.tables.artifacts', 'swarm_artifacts'),
            'durable_node_outputs' => (string) $config->get('swarm.tables.durable_node_outputs', 'swarm_durable_node_outputs'),
            'durable_branches' => (string) $config->get('swarm.tables.durable_branches', 'swarm_durable_branches'),
            'durable_signals' => (string) $config->get('swarm.tables.durable_signals', 'swarm_durable_signals'),
            'durable_waits' => (string) $config->get('swarm.tables.durable_waits', 'swarm_durable_waits'),
            'durable_labels' => (string) $config->get('swarm.tables.durable_labels', 'swarm_durable_labels'),
            'durable_details' => (string) $config->get('swarm.tables.durable_details', 'swarm_durable_details'),
            'durable_progress' => (string) $config->get('swarm.tables.durable_progress', 'swarm_durable_progress'),
            'durable_child_runs' => (string) $config->get('swarm.tables.durable_child_runs', 'swarm_durable_child_runs'),
            'durable_webhook_idempotency' => (string) $config->get('swarm.tables.durable_webhook_idempotency', 'swarm_durable_webhook_idempotency'),
        ];

        $counts = [];
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable($tables['history'])) {
            $this->components->warn("Skipping swarm pruning because history table [{$tables['history']}] does not exist. Run the Laravel Swarm migrations before pruning database persistence.");

            return self::SUCCESS;
        }

        foreach ($tables as $name => $table) {
            if (! $schema->hasTable($table)) {
                $counts[$name] = 0;

                if ($name !== 'durable_branches') {
                    $this->components->warn("Skipping {$name} pruning because table [{$table}] does not exist.");
                }

                continue;
            }

            $counts[$name] = $dryRun
                ? $this->countPrunableRows($connection, $config, $name, $table, $tables['history'])
                : $this->pruneTable($connection, $config, $name, $table, $tables['history']);
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';

        $this->components->info(sprintf(
            '%s %d history, %d context, and %d artifact records.',
            $verb,
            $counts['history'],
            $counts['contexts'],
            $counts['artifacts'],
        ));
        $this->components->info(sprintf(
            '%s %d normalized step record(s).',
            $verb,
            $counts['history_steps'],
        ));
        $this->components->info(sprintf(
            '%s %d stream event record(s).',
            $verb,
            $counts['stream_events'],
        ));
        $this->components->info(sprintf(
            '%s %d durable runtime, %d durable node output, and %d durable branch record(s).',
            $verb,
            $counts['durable'],
            $counts['durable_node_outputs'],
            $counts['durable_branches'],
        ));
        $this->components->info(sprintf(
            '%s %d durable signal, %d wait, %d label, %d detail, %d progress, and %d child run record(s).',
            $verb,
            $counts['durable_signals'],
            $counts['durable_waits'],
            $counts['durable_labels'],
            $counts['durable_details'],
            $counts['durable_progress'],
            $counts['durable_child_runs'],
        ));
        $this->components->info(sprintf(
            '%s %d durable webhook idempotency record(s).',
            $verb,
            $counts['durable_webhook_idempotency'],
        ));

        return self::SUCCESS;
    }

    protected function pruneQuery(Connection $connection, ConfigRepository $config, string $role, string $table, string $historyTable): Builder
    {
        $query = $connection->table($table);

        if ($role === 'history') {
            $query->where('expires_at', '<', now())
                ->whereIn('status', ['completed', 'failed', 'cancelled']);
        } elseif ($role === 'durable') {
            $query->whereIn('status', ['completed', 'failed', 'cancelled'])
                ->whereIn('run_id', function ($subquery) use ($historyTable): void {
                    $subquery->from($historyTable)
                        ->select('run_id')
                        ->where('expires_at', '<', now())
                        ->whereIn('status', ['completed', 'failed', 'cancelled']);
                });
        } elseif (in_array($role, ['durable_signals', 'durable_waits', 'durable_labels', 'durable_details', 'durable_progress'], true)) {
            $query->whereIn('run_id', function ($subquery) use ($historyTable): void {
                $subquery->from($historyTable)
                    ->select('run_id')
                    ->where('expires_at', '<', now())
                    ->whereIn('status', ['completed', 'failed', 'cancelled']);
            });
        } elseif ($role === 'durable_child_runs') {
            $query->whereIn('parent_run_id', function ($subquery) use ($historyTable): void {
                $subquery->from($historyTable)
                    ->select('run_id')
                    ->where('expires_at', '<', now())
                    ->whereIn('status', ['completed', 'failed', 'cancelled']);
            });
        } elseif ($role === 'durable_webhook_idempotency') {
            $staleCutoff = now()->subSeconds((int) $config->get('swarm.durable.webhooks.idempotency_ttl', 3600));

            $query->where(function ($query) use ($historyTable, $staleCutoff): void {
                $query->where(function ($query) use ($historyTable): void {
                    $query->whereNotNull('run_id')
                        ->whereIn('run_id', function ($subquery) use ($historyTable): void {
                            $subquery->from($historyTable)
                                ->select('run_id')
                                ->where('expires_at', '<', now())
                                ->whereIn('status', ['completed', 'failed', 'cancelled']);
                        });
                })->orWhere(function ($query) use ($staleCutoff): void {
                    $query->whereNull('run_id')
                        ->whereIn('status', ['failed', 'reserved'])
                        ->where('updated_at', '<', $staleCutoff);
                });
            });
        } else {
            $query->where('expires_at', '<', now())
                ->whereNotIn('run_id', function ($subquery) use ($historyTable): void {
                    $subquery->from($historyTable)
                        ->select('run_id')
                        ->whereIn('status', ['pending', 'running', 'paused', 'waiting']);
                });
        }

        return $query;
    }

    protected function countPrunableRows(Connection $connection, ConfigRepository $config, string $role, string $table, string $historyTable): int
    {
        return (int) $this->pruneQuery($connection, $config, $role, $table, $historyTable)->count();
    }

    protected function pruneTable(Connection $connection, ConfigRepository $config, string $role, string $table, string $historyTable): int
    {
        $deleted = 0;

        while (true) {
            $chunk = $this->pruneQuery($connection, $config, $role, $table, $historyTable)
                ->limit(self::CHUNK_SIZE)
                ->delete();

            if ($chunk === 0) {
                return $deleted;
            }

            $deleted += $chunk;
        }
    }
}
