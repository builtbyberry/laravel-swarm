<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'swarm:recover')]
class SwarmRecoverCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:recover {--run-id=} {--swarm=} {--limit=50}';

    protected $description = 'Redispatch recoverable durable swarm runs';

    public function handle(DurableSwarmManager $manager, SwarmAuditDispatcher $audit, ConfigRepository $config, Connection $connection): int
    {
        $runIdOption = $this->option('run-id');
        $swarmOption = $this->option('swarm');
        $targetRunId = is_string($runIdOption) && $runIdOption !== '' ? $runIdOption : null;
        $targetSwarmClass = is_string($swarmOption) && $swarmOption !== '' ? $swarmOption : null;
        $actorMetadata = ['actor' => Actor::system('artisan')->toArray()];

        try {
            $runIds = $manager->recover(
                runId: $targetRunId,
                swarmClass: $targetSwarmClass,
                limit: $this->optionInt('limit', 50),
            );
        } catch (Throwable $exception) {
            $audit->emit('command.recover', [
                'target_run_id' => $targetRunId,
                'target_swarm_class' => $targetSwarmClass,
                'status' => 'failed',
                'exception_class' => $exception::class,
                ...$audit->metadata($actorMetadata),
            ]);

            throw $exception;
        }

        $audit->emit('command.recover', [
            'target_run_id' => $targetRunId,
            'target_swarm_class' => $targetSwarmClass,
            'recovered_count' => count($runIds),
            'recovered_run_ids' => $runIds,
            'status' => count($runIds) > 0 ? 'recovered' : 'none_found',
            ...$audit->metadata($actorMetadata),
        ]);

        if ($runIds === []) {
            $this->components->info('No recoverable durable swarm runs were found.');
            $this->warnIfRelayNotRunning($config, $connection);

            return self::SUCCESS;
        }

        $this->components->info('Redispatched '.count($runIds).' durable swarm run(s).');
        $this->warnIfRelayNotRunning($config, $connection);

        return self::SUCCESS;
    }

    private function warnIfRelayNotRunning(ConfigRepository $config, Connection $connection): void
    {
        if ($config->get('swarm.persistence.driver') !== 'database') {
            return;
        }

        $outboxTable = (string) $config->get('swarm.tables.durable_outbox', 'swarm_durable_outbox');
        $reservationTimeoutSeconds = (int) $config->get('swarm.durable.relay.reservation_timeout_seconds', 60);

        $warningThresholdSeconds = (int) $config->get('swarm.durable.relay.stale_warning_threshold_seconds', 0);
        if ($warningThresholdSeconds <= 0) {
            $warningThresholdSeconds = $reservationTimeoutSeconds * 2;
        }

        $warningThreshold = now()->subSeconds($warningThresholdSeconds);
        $staleThreshold = now()->subSeconds($reservationTimeoutSeconds * 2);

        try {
            if (! $connection->getSchemaBuilder()->hasTable($outboxTable)) {
                return;
            }

            $agingCount = $connection->table($outboxTable)
                ->where('created_at', '<', $warningThreshold)
                ->where(fn ($q) => $q->whereNull('reserved_at')->orWhere('reserved_at', '<', $staleThreshold))
                ->count();

            if ($agingCount > 0) {
                $this->components->warn("{$agingCount} outbox row(s) aging past {$warningThresholdSeconds}s without being relayed — is swarm:relay scheduled?");
            }
        } catch (Throwable) {
            // This check must never crash recover.
        }
    }
}
