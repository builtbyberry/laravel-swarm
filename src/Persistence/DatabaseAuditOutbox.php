<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Database-backed audit outbox. Stores failed evidence records and replays
 * them through the bound SwarmAuditSink when swarm:relay --type=audit runs.
 *
 * Pending records are re-claimable after the reservation timeout (default
 * 60s, controlled by swarm.durable.relay.reservation_timeout_seconds — the
 * audit outbox shares the durable relay timeout). Records that exceed
 * swarm.audit.outbox.max_attempts (default 5) move to the 'dead_letter'
 * status and stop being re-claimed.
 *
 * @internal
 */
class DatabaseAuditOutbox implements AuditOutbox
{
    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected SwarmAuditSink $sink,
    ) {}

    public function enqueue(string $category, array $payload, bool $deadLetter = false): void
    {
        $now = Carbon::now('UTC');

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            // We can't queue what we can't serialize. Surface to the application
            // error handler so the broken payload is investigable rather than lost.
            report($exception);

            return;
        }

        $this->table()->insert([
            'category' => $category,
            'run_id' => is_string($payload['run_id'] ?? null) ? $payload['run_id'] : null,
            'payload' => $encoded,
            'attempts' => 0,
            'status' => $deadLetter ? 'dead_letter' : 'pending',
            'last_error' => null,
            'last_attempted_at' => null,
            'reserved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function drain(int $limit = 100): AuditDrainResult
    {
        if ($limit < 1) {
            return new AuditDrainResult(0, 0, 0, 0, 0);
        }

        $reservationTimeoutSeconds = (int) $this->config->get('swarm.durable.relay.reservation_timeout_seconds', 60);
        $maxAttempts = max(1, (int) $this->config->get('swarm.audit.outbox.max_attempts', 5));
        $now = Carbon::now('UTC');
        $staleThreshold = $now->copy()->subSeconds($reservationTimeoutSeconds);

        $entries = $this->connection->transaction(function () use ($now, $staleThreshold, $limit) {
            $query = $this->table()
                ->where('status', 'pending')
                ->where(function ($q) use ($staleThreshold): void {
                    $q->whereNull('reserved_at')
                        ->orWhere('reserved_at', '<', $staleThreshold);
                })
                ->orderBy('id')
                ->limit($limit);

            if ($this->connection->getDriverName() !== 'sqlite') {
                $query->lock('for update skip locked');
            }

            $entries = $query->get();

            if ($entries->isEmpty()) {
                return $entries;
            }

            $this->table()->whereIn('id', $entries->pluck('id')->all())->update(['reserved_at' => $now]);

            return $entries;
        });

        if ($entries->isEmpty()) {
            return new AuditDrainResult(0, 0, 0, 0, 0);
        }

        $claimed = $entries->count();
        $reclaimed = $entries->filter(fn (object $e): bool => $e->reserved_at !== null)->count();

        $replayed = 0;
        $deadLettered = 0;
        $failed = 0;
        $replayedIds = [];

        foreach ($entries as $entry) {
            $attempts = (int) $entry->attempts + 1;

            try {
                $payload = is_string($entry->payload)
                    ? json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR)
                    : (array) $entry->payload;
            } catch (\JsonException $exception) {
                // Permanently invalid stored payload; route to dead-letter and
                // surface via the error handler so it's investigable.
                report($exception);
                $this->table()->where('id', $entry->id)->update([
                    'status' => 'dead_letter',
                    'attempts' => $attempts,
                    'last_error' => 'invalid_json',
                    'last_attempted_at' => Carbon::now('UTC'),
                    'reserved_at' => null,
                    'updated_at' => Carbon::now('UTC'),
                ]);
                $deadLettered++;

                continue;
            }

            if (! is_array($payload)) {
                $this->table()->where('id', $entry->id)->update([
                    'status' => 'dead_letter',
                    'attempts' => $attempts,
                    'last_error' => 'payload_not_array',
                    'last_attempted_at' => Carbon::now('UTC'),
                    'reserved_at' => null,
                    'updated_at' => Carbon::now('UTC'),
                ]);
                $deadLettered++;

                continue;
            }

            try {
                $this->sink->emit($entry->category, $payload);
                $replayedIds[] = $entry->id;
                $replayed++;
            } catch (Throwable $exception) {
                report($exception);

                if ($attempts >= $maxAttempts) {
                    $this->table()->where('id', $entry->id)->update([
                        'status' => 'dead_letter',
                        'attempts' => $attempts,
                        'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                        'last_attempted_at' => Carbon::now('UTC'),
                        'reserved_at' => null,
                        'updated_at' => Carbon::now('UTC'),
                    ]);
                    $deadLettered++;
                } else {
                    $this->table()->where('id', $entry->id)->update([
                        'attempts' => $attempts,
                        'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                        'last_attempted_at' => Carbon::now('UTC'),
                        'reserved_at' => null,
                        'updated_at' => Carbon::now('UTC'),
                    ]);
                    $failed++;
                }
            }
        }

        if ($replayedIds !== []) {
            $this->table()->whereIn('id', $replayedIds)->delete();
        }

        return new AuditDrainResult($replayed, $deadLettered, $failed, $claimed, $reclaimed);
    }

    public function isAvailable(): bool
    {
        if ($this->config->get('swarm.persistence.driver') !== 'database') {
            return false;
        }

        try {
            return $this->connection->getSchemaBuilder()->hasTable($this->tableName());
        } catch (Throwable) {
            return false;
        }
    }

    public function assertReady(): void
    {
        if (! $this->isAvailable()) {
            throw new SwarmException(
                'Audit outbox is not available. The swarm_audit_outbox table is required for '
                .'swarm.audit.failure_policy=queue (or dead_letter). Run the package migrations or '
                .'switch to a different failure policy.'
            );
        }
    }

    protected function table(): Builder
    {
        return $this->connection->table($this->tableName());
    }

    protected function tableName(): string
    {
        return (string) $this->config->get('swarm.tables.audit_outbox', 'swarm_audit_outbox');
    }
}
