<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:prune')]
class SwarmPruneCommand extends Command
{
    protected $signature = 'swarm:prune';

    protected $description = 'Prune expired swarm database persistence records in bounded batches';

    protected const CHUNK_SIZE = 1000;

    public function handle(Connection $connection, ConfigRepository $config): int
    {
        $tables = [
            'durable' => (string) $config->get('swarm.tables.durable', 'swarm_durable_runs'),
            'history' => (string) $config->get('swarm.tables.history', 'swarm_run_histories'),
            'contexts' => (string) $config->get('swarm.tables.contexts', 'swarm_contexts'),
            'artifacts' => (string) $config->get('swarm.tables.artifacts', 'swarm_artifacts'),
        ];

        $deleted = [];
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable($tables['history'])) {
            $this->components->warn("Skipping swarm pruning because history table [{$tables['history']}] does not exist. Run the Laravel Swarm migrations before pruning database persistence.");

            return self::SUCCESS;
        }

        foreach ($tables as $name => $table) {
            if (! $schema->hasTable($table)) {
                $deleted[$name] = 0;
                $this->components->warn("Skipping {$name} pruning because table [{$table}] does not exist.");

                continue;
            }

            $deleted[$name] = $this->pruneTable($connection, $name, $table, $tables['history']);
        }

        $this->components->info(sprintf(
            'Pruned %d history, %d context, and %d artifact records.',
            $deleted['history'],
            $deleted['contexts'],
            $deleted['artifacts'],
        ));
        $this->components->info(sprintf(
            'Pruned %d durable runtime record(s).',
            $deleted['durable'],
        ));

        return self::SUCCESS;
    }

    protected function pruneTable(Connection $connection, string $role, string $table, string $historyTable): int
    {
        $deleted = 0;

        while (true) {
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
            } else {
                $query->where('expires_at', '<', now())
                    ->whereNotIn('run_id', function ($subquery) use ($historyTable): void {
                        $subquery->from($historyTable)
                            ->select('run_id')
                            ->whereIn('status', ['pending', 'running', 'paused']);
                    });
            }

            $chunk = $query
                ->limit(self::CHUNK_SIZE)
                ->delete();

            if ($chunk === 0) {
                return $deleted;
            }

            $deleted += $chunk;
        }
    }
}
