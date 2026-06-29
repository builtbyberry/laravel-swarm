<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Jobs\CompactSwarmRun;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:compact')]
class SwarmCompactCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:compact {--run-id=} {--limit=50}';

    protected $description = 'Dispatch background compaction jobs for sealed swarm runs';

    public function handle(SwarmAuditDispatcher $audit, ConfigRepository $config, Connection $connection): int
    {
        $runIdOption = $this->option('run-id');
        $targetRunId = is_string($runIdOption) && $runIdOption !== '' ? $runIdOption : null;
        $limit = $this->optionInt('limit', 50);
        $actorMetadata = ['actor' => Actor::system('artisan')->toArray()];

        if ($config->get('swarm.persistence.driver') !== 'database') {
            $audit->emit('command.compact', [
                'target_run_id' => $targetRunId,
                'dispatched_count' => 0,
                'dispatched_run_ids' => [],
                'status' => 'skipped_non_database_driver',
                ...$audit->metadata($actorMetadata),
            ]);

            $this->components->info('Compaction requires the database persistence driver; nothing to do.');

            return self::SUCCESS;
        }

        $runIds = $targetRunId !== null
            ? [$targetRunId]
            : $this->discoverEligibleRuns($config, $connection, $limit);

        foreach ($runIds as $runId) {
            dispatch(new CompactSwarmRun($runId));
        }

        $audit->emit('command.compact', [
            'target_run_id' => $targetRunId,
            'dispatched_count' => count($runIds),
            'dispatched_run_ids' => $runIds,
            'status' => count($runIds) > 0 ? 'dispatched' : 'none_found',
            ...$audit->metadata($actorMetadata),
        ]);

        if ($runIds === []) {
            $this->components->info('No runs with unsealed events were found.');

            return self::SUCCESS;
        }

        $this->components->info('Dispatched '.count($runIds).' compaction job(s).');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function discoverEligibleRuns(ConfigRepository $config, Connection $connection, int $limit): array
    {
        $hotTable = (string) $config->get('swarm.tables.stream_events', 'swarm_stream_events');
        $durableTable = (string) $config->get('swarm.tables.durable', 'swarm_durable_runs');

        $quarantinedIds = $connection->table($durableTable)
            ->whereNotNull('compaction_quarantined_at')
            ->pluck('run_id')
            ->all();

        $query = $connection->table($hotTable)
            ->select('run_id')
            ->where('event_type', 'swarm_causal_seal_barrier')
            ->distinct()
            ->limit($limit);

        if ($quarantinedIds !== []) {
            $query->whereNotIn('run_id', $quarantinedIds);
        }

        /** @var list<string> */
        return $query->pluck('run_id')->all();
    }
}
