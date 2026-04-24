<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:prune')]
class SwarmPruneCommand extends Command
{
    protected $signature = 'swarm:prune';

    protected $description = 'Prune expired swarm database persistence records in bounded batches';

    protected const CHUNK_SIZE = 1000;

    public function handle(ConnectionInterface $connection, ConfigRepository $config): int
    {
        $tables = [
            'history' => (string) $config->get('swarm.tables.history', 'swarm_run_histories'),
            'contexts' => (string) $config->get('swarm.tables.contexts', 'swarm_contexts'),
            'artifacts' => (string) $config->get('swarm.tables.artifacts', 'swarm_artifacts'),
        ];

        $deleted = [];

        foreach ($tables as $name => $table) {
            $deleted[$name] = $this->pruneTable($connection, $name, $table, $tables['history']);
        }

        $this->components->info(sprintf(
            'Pruned %d history, %d context, and %d artifact records.',
            $deleted['history'],
            $deleted['contexts'],
            $deleted['artifacts'],
        ));

        return self::SUCCESS;
    }

    protected function pruneTable(ConnectionInterface $connection, string $role, string $table, string $historyTable): int
    {
        $deleted = 0;

        while (true) {
            $query = $connection->table($table)
                ->where('expires_at', '<', now());

            if ($role === 'history') {
                $query->whereIn('status', ['completed', 'failed']);
            } else {
                $query->whereNotIn('run_id', function ($subquery) use ($historyTable): void {
                    $subquery->from($historyTable)
                        ->select('run_id')
                        ->where('status', 'running');
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
