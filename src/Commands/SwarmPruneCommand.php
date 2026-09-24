<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'swarm:prune')]
class SwarmPruneCommand extends Command
{
    protected $signature = 'swarm:prune {--dry-run : Report rows that would be pruned without deleting}';

    protected $description = 'Prune expired swarm database persistence records in bounded batches (use --dry-run to preview; retention.prevent_prune disables deletes)';

    protected const CHUNK_SIZE = 1000;

    public function handle(Connection $connection, ConfigRepository $config, SwarmAuditDispatcher $audit): int
    {
        /** @var SwarmPersistenceCipher $cipher */
        $cipher = $this->laravel->make(SwarmPersistenceCipher::class);
        /** @var FilesystemFactory $filesystems */
        $filesystems = $this->laravel->make(FilesystemFactory::class);
        $actorMetadata = ['actor' => Actor::system('artisan')->toArray()];
        $preventPrune = $config->get('swarm.retention.prevent_prune', false) === true;

        if ($preventPrune && ! $this->option('dry-run')) {
            $this->components->warn('Swarm pruning is disabled because swarm.retention.prevent_prune is true (SWARM_PREVENT_PRUNE). Use --dry-run to inspect impact without deleting.');
            $audit->emit('command.prune', [
                'dry_run' => false,
                'prevent_prune' => true,
                'status' => 'skipped',
                'counts' => [],
                ...$audit->metadata($actorMetadata),
            ]);

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
            'durable_node_states' => (string) $config->get('swarm.tables.durable_node_states', 'swarm_durable_node_states'),
            'durable_run_state' => (string) $config->get('swarm.tables.durable_run_state', 'swarm_durable_run_state'),
            'durable_node_outputs' => (string) $config->get('swarm.tables.durable_node_outputs', 'swarm_durable_node_outputs'),
            'durable_branches' => (string) $config->get('swarm.tables.durable_branches', 'swarm_durable_branches'),
            'durable_signals' => (string) $config->get('swarm.tables.durable_signals', 'swarm_durable_signals'),
            'durable_waits' => (string) $config->get('swarm.tables.durable_waits', 'swarm_durable_waits'),
            'durable_labels' => (string) $config->get('swarm.tables.durable_labels', 'swarm_durable_labels'),
            'durable_details' => (string) $config->get('swarm.tables.durable_details', 'swarm_durable_details'),
            'durable_progress' => (string) $config->get('swarm.tables.durable_progress', 'swarm_durable_progress'),
            'durable_child_runs' => (string) $config->get('swarm.tables.durable_child_runs', 'swarm_durable_child_runs'),
            'durable_webhook_idempotency' => (string) $config->get('swarm.tables.durable_webhook_idempotency', 'swarm_durable_webhook_idempotency'),
            'durable_outbox' => (string) $config->get('swarm.tables.durable_outbox', 'swarm_durable_outbox'),
            'audit_outbox' => (string) $config->get('swarm.tables.audit_outbox', 'swarm_audit_outbox'),
            'native_inputs' => (string) $config->get('swarm.tables.native_inputs', 'swarm_native_inputs'),
        ];

        // Seed every table key to zero so the count shape is fully known: the
        // loop below assigns each key, but pre-filling lets static analysis
        // prove the summary accesses below are present.
        $counts = array_fill_keys(array_keys($tables), 0);
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
                : ($name === 'native_inputs'
                    ? $this->pruneNativeInputs($connection, $config, $table, $tables['history'], $cipher, $filesystems)
                    : $this->pruneTable($connection, $config, $name, $table, $tables['history']));
        }

        $audit->emit('command.prune', [
            'dry_run' => $dryRun,
            'prevent_prune' => false,
            'status' => $dryRun ? 'dry_run' : 'pruned',
            'counts' => $counts,
            ...$audit->metadata($actorMetadata),
        ]);

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
            '%s %d durable runtime, %d durable node state, %d durable run state, %d durable node output, and %d durable branch record(s).',
            $verb,
            $counts['durable'],
            $counts['durable_node_states'],
            $counts['durable_run_state'],
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
        $this->components->info(sprintf(
            '%s %d durable outbox record(s).',
            $verb,
            $counts['durable_outbox'],
        ));
        $this->components->info(sprintf(
            '%s %d audit outbox dead-letter record(s).',
            $verb,
            $counts['audit_outbox'],
        ));
        $this->components->info(sprintf(
            '%s %d expired native input operational envelope(s).',
            $verb,
            $counts['native_inputs'],
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
        } elseif (in_array($role, ['durable_node_states', 'durable_run_state', 'durable_signals', 'durable_waits', 'durable_labels', 'durable_details', 'durable_progress'], true)) {
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
        } elseif ($role === 'durable_outbox') {
            // Outbox rows are cascade-deleted with their parent run, so this catches
            // any orphans that were not cleaned up (e.g., reserved rows whose run is now terminal).
            $query->whereIn('run_id', function ($subquery) use ($historyTable): void {
                $subquery->from($historyTable)
                    ->select('run_id')
                    ->where('expires_at', '<', now())
                    ->whereIn('status', ['completed', 'failed', 'cancelled']);
            });
        } elseif ($role === 'audit_outbox') {
            // Audit outbox rows are NOT tied to a run cascade; they are independent
            // failure-retry records. Only dead-letter rows are eligible for pruning,
            // and only when the operator has explicitly opted in via
            // swarm.audit.outbox.dead_letter_retention_days. Pending and reserved
            // rows are never pruned here — staleness/retry is the relay's job.
            //
            // Default null preserves all dead-letter records indefinitely so regulated
            // callers (Part 11, etc.) do not silently lose evidence before reconciliation.
            $retentionDays = $config->get('swarm.audit.outbox.dead_letter_retention_days');

            if (! is_int($retentionDays) || $retentionDays < 1) {
                // No retention configured — return a query that matches no rows so
                // countPrunableRows reports 0 and pruneTable deletes nothing.
                $query->whereRaw('1 = 0');
            } else {
                $query->where('status', 'dead_letter')
                    ->where('last_attempted_at', '<', now()->subDays($retentionDays));
            }
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

    protected function pruneNativeInputs(Connection $connection, ConfigRepository $config, string $table, string $historyTable, SwarmPersistenceCipher $cipher, FilesystemFactory $filesystems): int
    {
        $deleted = 0;
        $lastId = null;

        while (true) {
            $query = $this->pruneQuery($connection, $config, 'native_inputs', $table, $historyTable);
            if (is_string($lastId)) {
                $query->where('id', '>', $lastId);
            }

            $rows = $query->orderBy('id')->limit(self::CHUNK_SIZE)
                ->get(['id', 'run_id', 'payload']);

            if ($rows->isEmpty()) {
                return $deleted;
            }

            $lastId = (string) $rows->last()->id;

            $ids = [];
            foreach ($rows as $row) {
                try {
                    $sealed = (string) $row->payload;
                    if (! str_starts_with($sealed, SwarmPersistenceCipher::PREFIX)) {
                        throw new \RuntimeException('payload is not sealed');
                    }

                    $payload = json_decode((string) $cipher->openStrict($sealed), true, 512, JSON_THROW_ON_ERROR);
                    $allDeleted = true;
                    foreach ($payload['attachments'] ?? [] as $attachment) {
                        if (! is_array($attachment) || ($attachment['swarm_owned'] ?? false) !== true) {
                            continue;
                        }

                        $disk = $attachment['disk'] ?? null;
                        $path = $attachment['path'] ?? null;
                        if (! is_string($disk) || ! is_string($path)
                            || ! str_starts_with($path, 'swarm/native-inputs/'.(string) $row->run_id.'/')) {
                            $allDeleted = false;

                            continue;
                        }

                        $filesystem = $filesystems->disk($disk);
                        if ($filesystem->exists($path) && ! $filesystem->delete($path)) {
                            $allDeleted = false;
                        }
                    }

                    if ($allDeleted) {
                        $ids[] = $row->id;
                    } else {
                        $this->components->warn("Retaining native input envelope [{$row->id}] because one or more owned files could not be deleted.");
                    }
                } catch (Throwable $exception) {
                    $this->components->warn("Retaining native input envelope [{$row->id}] because cleanup failed: {$exception->getMessage()}");
                }
            }

            if ($ids !== []) {
                $deleted += $connection->table($table)->whereIn('id', $ids)->delete();
            }
        }
    }
}
