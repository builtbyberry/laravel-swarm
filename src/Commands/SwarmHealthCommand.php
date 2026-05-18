<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'swarm:health')]
class SwarmHealthCommand extends Command
{
    protected $signature = 'swarm:health {--durable : Also verify durable database runtime tables} {--json : Output machine-readable health results}';

    protected $description = 'Verify Laravel Swarm persistence readiness';

    public function handle(Application $app, ConfigRepository $config, Connection $connection): int
    {
        $checks = [
            ['component' => 'Context', 'abstract' => ContextStore::class, 'config_key' => 'context'],
            ['component' => 'Artifacts', 'abstract' => ArtifactRepository::class, 'config_key' => 'artifacts'],
            ['component' => 'History', 'abstract' => RunHistoryStore::class, 'config_key' => 'history'],
            ['component' => 'Stream replay', 'abstract' => StreamEventStore::class, 'config_key' => 'streaming.replay'],
        ];

        if ($this->option('durable') === true) {
            $checks[] = ['component' => 'Durable runtime', 'abstract' => DurableRunStore::class, 'config_key' => null];
        }

        $results = array_map(
            fn (array $check): array => $this->runCheck($app, $config, $check),
            $checks,
        );

        if ($this->option('durable') === true) {
            $results[] = $this->runActiveContextCaptureCheck($config);
            $results[] = [
                'component' => 'Relay scheduling',
                'driver' => 'n/a',
                'store' => 'n/a',
                'status' => 'note',
                'details' => "swarm:relay must run every minute for durable execution to advance — Schedule::command('swarm:relay')->everyMinute()",
            ];
            $results[] = $this->runOutboxStalenessCheck($config, $connection);
            $results[] = $this->runQueueRoutingCheck($config, $connection);
        }

        if ($this->option('json') === true) {
            $this->line((string) json_encode([
                'ok' => collect($results)->every(fn (array $result): bool => $result['status'] === 'ok'),
                'checks' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Component', 'Driver', 'Store', 'Status', 'Details'],
                array_map(fn (array $result): array => [
                    $result['component'],
                    $result['driver'],
                    $result['store'],
                    $result['status'],
                    $result['details'],
                ], $results),
            );
        }

        return collect($results)->contains(fn (array $result): bool => $result['status'] === 'failed')
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return array{component: string, driver: string, store: string, status: string, details: string}
     */
    protected function runActiveContextCaptureCheck(ConfigRepository $config): array
    {
        $enabled = (bool) $config->get('swarm.capture.active_context', false);

        if ($enabled) {
            return [
                'component' => 'Active context capture',
                'driver' => 'n/a',
                'store' => 'n/a',
                'status' => 'ok',
                'details' => 'swarm.capture.active_context is enabled',
            ];
        }

        return [
            'component' => 'Active context capture',
            'driver' => 'n/a',
            'store' => 'n/a',
            'status' => 'failed',
            'details' => 'Queued and durable swarms require active runtime context persistence so workers can continue or recover the run. Enable [swarm.capture.active_context] (SWARM_CAPTURE_ACTIVE_CONTEXT=true) or use synchronous execution.',
        ];
    }

    /**
     * @return array{component: string, driver: string, store: string, status: string, details: string}
     */
    protected function runOutboxStalenessCheck(ConfigRepository $config, Connection $connection): array
    {
        $outboxTable = (string) $config->get('swarm.tables.durable_outbox', 'swarm_durable_outbox');
        $reservationTimeoutSeconds = (int) $config->get('swarm.durable.relay.reservation_timeout_seconds', 60);

        // 2× the reservation timeout: gives the relay at least one full reclaim cycle
        // before alerting. A single missed relay run reclaims stale reservations on the
        // next run; two missed cycles indicates the relay is not running at all.
        $staleThreshold = now()->subSeconds($reservationTimeoutSeconds * 2);

        // Resolve the warning threshold; 0 means "use 2× reservation_timeout_seconds".
        $warningThresholdSeconds = (int) $config->get('swarm.durable.relay.stale_warning_threshold_seconds', 0);
        if ($warningThresholdSeconds <= 0) {
            $warningThresholdSeconds = $reservationTimeoutSeconds * 2;
        }
        $warningThreshold = now()->subSeconds($warningThresholdSeconds);

        try {
            if (! $connection->getSchemaBuilder()->hasTable($outboxTable)) {
                return [
                    'component' => 'Outbox relay',
                    'driver' => 'database',
                    'store' => 'n/a',
                    'status' => 'failed',
                    'details' => "Outbox table [{$outboxTable}] does not exist. Run migrations.",
                ];
            }

            // Total pending: unclaimed or with an expired reservation.
            $totalPending = $connection->table($outboxTable)
                ->where(fn ($q) => $q->whereNull('reserved_at')->orWhere('reserved_at', '<', $staleThreshold))
                ->count();

            if ($totalPending === 0) {
                return [
                    'component' => 'Outbox relay',
                    'driver' => 'database',
                    'store' => 'n/a',
                    'status' => 'ok',
                    'details' => 'no pending rows',
                ];
            }

            // Aging: pending AND older than the warning threshold.
            $agingCount = $connection->table($outboxTable)
                ->where('created_at', '<', $warningThreshold)
                ->where(fn ($q) => $q->whereNull('reserved_at')->orWhere('reserved_at', '<', $staleThreshold))
                ->count();

            if ($agingCount > 0) {
                return [
                    'component' => 'Outbox relay',
                    'driver' => 'database',
                    'store' => 'n/a',
                    'status' => 'warning',
                    'details' => "{$agingCount} row(s) aging past {$warningThresholdSeconds}s — is swarm:relay scheduled?",
                ];
            }

            return [
                'component' => 'Outbox relay',
                'driver' => 'database',
                'store' => 'n/a',
                'status' => 'ok',
                'details' => "{$totalPending} pending row(s), relay appears active",
            ];
        } catch (Throwable $exception) {
            return [
                'component' => 'Outbox relay',
                'driver' => 'database',
                'store' => 'n/a',
                'status' => 'failed',
                'details' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{component: string, driver: string, store: string, status: string, details: string}
     */
    protected function runQueueRoutingCheck(ConfigRepository $config, Connection $connection): array
    {
        $outboxTable = (string) $config->get('swarm.tables.durable_outbox', 'swarm_durable_outbox');
        $knownConnections = array_keys((array) $config->get('queue.connections', []));

        try {
            if (! $connection->getSchemaBuilder()->hasTable($outboxTable)) {
                return [
                    'component' => 'Outbox queue routing',
                    'driver' => 'database',
                    'store' => 'n/a',
                    'status' => 'failed',
                    'details' => "Outbox table [{$outboxTable}] does not exist. Run migrations.",
                ];
            }

            $unknownCount = $connection->table($outboxTable)
                ->whereNotNull('queue_connection')
                ->whereNotIn('queue_connection', $knownConnections)
                ->count();

            if ($unknownCount > 0) {
                return [
                    'component' => 'Outbox queue routing',
                    'driver' => 'database',
                    'store' => 'n/a',
                    'status' => 'warning',
                    'details' => "{$unknownCount} row(s) reference an unknown queue_connection — these will be permanently skipped at drain. Verify queue.connections matches what was stored when runs were dispatched.",
                ];
            }

            return [
                'component' => 'Outbox queue routing',
                'driver' => 'database',
                'store' => 'n/a',
                'status' => 'ok',
                'details' => 'all connections known',
            ];
        } catch (Throwable $exception) {
            return [
                'component' => 'Outbox queue routing',
                'driver' => 'database',
                'store' => 'n/a',
                'status' => 'failed',
                'details' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array{component: string, abstract: class-string, config_key: string|null}  $check
     * @return array{component: string, driver: string, store: string, status: string, details: string}
     */
    protected function runCheck(Application $app, ConfigRepository $config, array $check): array
    {
        $driver = 'unknown';
        $storeName = $this->storeName($config, $check['config_key']);

        try {
            $store = $app->make($check['abstract']);

            if (! is_object($store)) {
                throw new \RuntimeException('The resolved store is not an object.');
            }

            $driver = $this->driverFor($store);
            $storeName = $driver === 'cache' ? $storeName : 'n/a';

            if (! method_exists($store, 'assertReady')) {
                throw new \RuntimeException('Readiness check is not available for the resolved store.');
            }

            $store->assertReady();

            return [
                'component' => $check['component'],
                'driver' => $driver,
                'store' => $storeName,
                'status' => 'ok',
                'details' => 'ready',
            ];
        } catch (Throwable $exception) {
            return [
                'component' => $check['component'],
                'driver' => $driver,
                'store' => $storeName,
                'status' => 'failed',
                'details' => $exception->getMessage(),
            ];
        }
    }

    protected function driverFor(object $store): string
    {
        $class = $store::class;

        if (str_contains($class, '\\Cache')) {
            return 'cache';
        }

        if (str_contains($class, '\\Database')) {
            return 'database';
        }

        return 'custom';
    }

    protected function storeName(ConfigRepository $config, ?string $configKey): string
    {
        if ($configKey === null) {
            return 'n/a';
        }

        $store = $config->get("swarm.{$configKey}.store");

        return $store !== null && $store !== '' ? (string) $store : 'default';
    }
}
