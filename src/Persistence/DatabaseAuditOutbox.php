<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
class DatabaseAuditOutbox implements AuditOutbox, ReadableAuditOutbox
{
    use SafeReporting;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected SwarmAuditSink $sink,
        protected SwarmPersistenceCipher $cipher,
        protected ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger;
    }

    public function enqueue(string $category, array $payload, bool $deadLetter = false): void
    {
        $now = Carbon::now('UTC');

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            // We can't queue what we can't serialize. Surface to the application
            // error handler so the broken payload is investigable rather than lost.
            $this->safeReport($exception);

            return;
        }

        $this->table()->insert([
            'category' => $category,
            'run_id' => is_string($payload['run_id'] ?? null) ? $payload['run_id'] : null,
            'payload' => $this->cipher->seal($encoded),
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
        /** @var list<array{id: int, category: string, run_id: ?string, attempts: int, error: string}> $failedGroup */
        $failedGroup = [];
        /** @var list<array{id: int, category: string, run_id: ?string, attempts: int, error: string}> $deadLetterGroup */
        $deadLetterGroup = [];

        foreach ($entries as $entry) {
            $attempts = (int) $entry->attempts + 1;
            $rawPayload = is_string($entry->payload) ? $this->cipher->open($entry->payload) : null;

            try {
                $payload = is_string($rawPayload)
                    ? json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR)
                    : (array) $entry->payload;
            } catch (\JsonException $exception) {
                // Permanently invalid stored payload; route to dead-letter and
                // surface via the error handler so it's investigable.
                $this->safeReport($exception);
                $deadLetterGroup[] = ['id' => (int) $entry->id, 'category' => (string) $entry->category, 'run_id' => $entry->run_id, 'attempts' => $attempts, 'error' => 'invalid_json'];
                $deadLettered++;

                continue;
            }

            if (! is_array($payload)) {
                $deadLetterGroup[] = ['id' => (int) $entry->id, 'category' => (string) $entry->category, 'run_id' => $entry->run_id, 'attempts' => $attempts, 'error' => 'payload_not_array'];
                $deadLettered++;

                continue;
            }

            try {
                $this->sink->emit((string) $entry->category, $payload);
                $replayedIds[] = (int) $entry->id;
                $replayed++;
            } catch (Throwable $exception) {
                $this->safeReport($exception);
                $error = mb_substr($exception->getMessage(), 0, 1000);

                if ($attempts >= $maxAttempts) {
                    $deadLetterGroup[] = ['id' => (int) $entry->id, 'category' => (string) $entry->category, 'run_id' => $entry->run_id, 'attempts' => $attempts, 'error' => $error];
                    $deadLettered++;
                } else {
                    $failedGroup[] = ['id' => (int) $entry->id, 'category' => (string) $entry->category, 'run_id' => $entry->run_id, 'attempts' => $attempts, 'error' => $error];
                    $failed++;
                }
            }
        }

        if ($replayedIds !== []) {
            $this->table()->whereIn('id', $replayedIds)->delete();
        }

        if ($failedGroup !== []) {
            $this->writeFailedGroup($failedGroup);
        }

        if ($deadLetterGroup !== []) {
            $this->writeDeadLetterGroup($deadLetterGroup);
        }

        return new AuditDrainResult($replayed, $deadLettered, $failed, $claimed, $reclaimed);
    }

    /**
     * Batch-persist a group of retry writes via upsert(), falling back to
     * independent per-row updates if the atomic batch statement fails (e.g. a
     * single malformed row) so the rest of the group still persists.
     *
     * @param  list<array{id: int, category: string, run_id: ?string, attempts: int, error: string}>  $group
     */
    protected function writeFailedGroup(array $group): void
    {
        $now = Carbon::now('UTC');
        $rows = array_map(fn (array $e): array => [
            'id' => $e['id'],
            'attempts' => $e['attempts'],
            'last_error' => $this->cipher->seal($e['error']),
            'last_attempted_at' => $now,
            'reserved_at' => null,
            'updated_at' => $now,
        ], $group);

        try {
            $this->upsertBatch($rows, ['attempts', 'last_error', 'last_attempted_at', 'reserved_at', 'updated_at']);

            return;
        } catch (Throwable $exception) {
            // A single malformed row can fail the whole atomic upsert statement.
            // Fall back to independent per-row writes so the rest of the group
            // still persists — matches the pre-batching failure isolation.
            $this->safeReport($exception);
        }

        foreach ($group as $entry) {
            $this->table()->where('id', $entry['id'])->update([
                'attempts' => $entry['attempts'],
                'last_error' => $this->cipher->seal($entry['error']),
                'last_attempted_at' => $now,
                'reserved_at' => null,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Batch-persist a group of dead-letter transitions via upsert(), falling
     * back to independent per-row updates on batch failure. The per-row
     * dead-letter log fires only after that row's write has succeeded,
     * regardless of which write path ran.
     *
     * @param  list<array{id: int, category: string, run_id: ?string, attempts: int, error: string}>  $group
     */
    protected function writeDeadLetterGroup(array $group): void
    {
        $now = Carbon::now('UTC');
        $rows = array_map(fn (array $e): array => [
            'id' => $e['id'],
            'status' => 'dead_letter',
            'attempts' => $e['attempts'],
            'last_error' => $this->cipher->seal($e['error']),
            'last_attempted_at' => $now,
            'reserved_at' => null,
            'updated_at' => $now,
        ], $group);

        try {
            $this->upsertBatch($rows, ['status', 'attempts', 'last_error', 'last_attempted_at', 'reserved_at', 'updated_at']);

            foreach ($group as $entry) {
                $this->logDeadLetter($entry);
            }

            return;
        } catch (Throwable $exception) {
            $this->safeReport($exception);
        }

        foreach ($group as $entry) {
            $this->table()->where('id', $entry['id'])->update([
                'status' => 'dead_letter',
                'attempts' => $entry['attempts'],
                'last_error' => $this->cipher->seal($entry['error']),
                'last_attempted_at' => $now,
                'reserved_at' => null,
                'updated_at' => $now,
            ]);
            $this->logDeadLetter($entry);
        }
    }

    /**
     * @param  array{id: int, category: string, run_id: ?string, attempts: int, error: string}  $entry
     */
    protected function logDeadLetter(array $entry): void
    {
        $this->safeLog($this->logger, 'error', 'Swarm audit record reached dead_letter status.', [
            'category' => $entry['category'],
            'run_id' => $entry['run_id'],
            'attempts' => $entry['attempts'],
            'last_error' => $entry['error'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $updateColumns
     */
    protected function upsertBatch(array $rows, array $updateColumns): void
    {
        $this->table()->upsert($rows, ['id'], $updateColumns);
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

    public function pending(int $limit = 100): array
    {
        return $this->readRows('pending', $limit);
    }

    public function deadLettered(int $limit = 100): array
    {
        return $this->readRows('dead_letter', $limit);
    }

    public function healthSummary(): array
    {
        if (! $this->isAvailable()) {
            return [
                'available' => false,
                'pending' => 0,
                'dead_letter' => 0,
                'reserved' => 0,
                'oldest_pending_at' => null,
            ];
        }

        $reservationTimeoutSeconds = (int) $this->config->get('swarm.durable.relay.reservation_timeout_seconds', 60);
        $freshThreshold = Carbon::now('UTC')->subSeconds($reservationTimeoutSeconds);

        $pending = (int) $this->table()->where('status', 'pending')->count();
        $deadLetter = (int) $this->table()->where('status', 'dead_letter')->count();
        $reserved = (int) $this->table()
            ->where('status', 'pending')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '>=', $freshThreshold)
            ->count();
        $oldestPendingAt = $this->table()
            ->where('status', 'pending')
            ->min('created_at');

        return [
            'available' => true,
            'pending' => $pending,
            'dead_letter' => $deadLetter,
            'reserved' => $reserved,
            'oldest_pending_at' => $oldestPendingAt !== null ? (string) $oldestPendingAt : null,
        ];
    }

    /**
     * Pure-SELECT read of outbox rows by status, newest first, display-decrypted
     * per row. Never writes `reserved_at` and never deletes, so it coexists with a
     * concurrent `swarm:relay --type=audit` drainer instead of stealing its rows
     * (record 632).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function readRows(string $status, int $limit): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return $this->table()
            ->where('status', $status)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (object $record): array => $this->mapRow($record))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapRow(object $record): array
    {
        [$lastError, $lastErrorAvailable] = $this->cipher->openForDisplay(
            $record->last_error === null ? null : (string) $record->last_error,
        );

        [$rawPayload, $payloadAvailable] = $this->cipher->openForDisplay(
            $record->payload === null ? null : (string) $record->payload,
        );

        $payload = null;

        if ($payloadAvailable && is_string($rawPayload)) {
            $decoded = json_decode($rawPayload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                $payloadAvailable = false;
            }
        }

        return [
            'id' => (int) $record->id,
            'category' => $record->category,
            'run_id' => $record->run_id,
            'status' => $record->status,
            'attempts' => (int) $record->attempts,
            'last_error' => $lastError,
            'last_error_available' => $lastErrorAvailable,
            'payload' => $payload,
            'payload_available' => $payloadAvailable,
            'reserved_at' => $record->reserved_at ?? null,
            'last_attempted_at' => $record->last_attempted_at ?? null,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
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
