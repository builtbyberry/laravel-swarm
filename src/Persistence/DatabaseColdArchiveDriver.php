<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmUnknownEvent;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Database-backed cold archive driver (#286).
 *
 * Stores cold-graduated data in a single `swarm_cold_archives` table. Each row
 * is either a raw event (archive_type='event') or a sealed fold-snapshot plus
 * base pointer (archive_type='snapshot'). Both surfaces are retained so:
 *   - resume reads the snapshot (fast, single row)
 *   - audit reads the raw events (full history, unchanged payloads)
 *
 * This driver is stateless: every method issues a fresh query. No mutable
 * per-run instance fields — safe as a singleton under Octane.
 *
 * @internal
 */
class DatabaseColdArchiveDriver implements ColdArchiveDriver
{
    use InteractsWithJsonColumns;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
    ) {}

    /**
     * Returns the hot/cold boundary sequence number, or 0 if no data has been
     * graduated yet. A return of 0 means hot is the complete event set.
     */
    public function basePointer(string $runId): int
    {
        $row = $this->table()
            ->where('run_id', $runId)
            ->where('archive_type', 'snapshot')
            ->value('base_pointer');

        return $row !== null ? (int) $row : 0;
    }

    /**
     * Yields raw SwarmStreamEvent objects for this run with DB id < $belowSequence,
     * in ascending causal order. Empty when no cold events exist (not an error).
     *
     * @return iterable<int, SwarmStreamEvent>
     */
    public function readEvents(string $runId, int $belowSequence): iterable
    {
        $query = $this->table()
            ->where('run_id', $runId)
            ->where('archive_type', 'event')
            ->where('sequence', '<', $belowSequence)
            ->orderBy('sequence');

        foreach ($query->cursor() as $record) {
            $event = SwarmStreamEvent::fromArray($this->decodeJson($record->payload, []));
            if (! ($event instanceof SwarmUnknownEvent)) {
                yield $event;
            }
        }
    }

    public function assertReady(): void
    {
        $table = (string) $this->config->get('swarm.tables.cold_archives', 'swarm_cold_archives');
        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            throw new SwarmException("Database-backed cold archive requires the [{$table}] table.");
        }

        if (! $schema->hasColumns($table, ['id', 'run_id', 'archive_type', 'sequence', 'payload', 'base_pointer'])) {
            throw new SwarmException("Database-backed cold archive requires runtime columns on [{$table}].");
        }
    }

    /**
     * Deletes all cold archive rows for this run (both events and snapshot).
     * Propagates hot-store forget() to cold so deletion is complete across tiers.
     */
    public function forget(string $runId): void
    {
        $this->table()->where('run_id', $runId)->delete();
    }

    /**
     * Returns the sealed fold-snapshot string for operational resume, or null if
     * nothing has been graduated yet. The caller MUST decrypt via openStrict() and
     * wrap DecryptException into a SwarmException — this method does not decrypt.
     * Driver/network failures throw, never return null.
     */
    public function readSnapshot(string $runId): ?string
    {
        $row = $this->table()
            ->where('run_id', $runId)
            ->where('archive_type', 'snapshot')
            ->first();

        return $row !== null ? (string) $row->payload : null;
    }

    /**
     * Decrypt the cold snapshot for operational resume, failing loud on a wrong
     * or rotated APP_KEY (#286 / #212 convention).
     *
     * This convenience method wraps the caller pattern documented on
     * {@see ColdArchiveDriver::readSnapshot()}: fetch the sealed string, run it
     * through openStrict(), and translate a DecryptException into a re-dispatchable
     * SwarmException so the operator knows exactly why the resume failed and what
     * to do (re-dispatch the run).
     *
     * Returns null when nothing has been graduated yet (readSnapshot returned null).
     * Returns the decoded array on success.
     *
     * @return array<string, mixed>|null
     */
    public function readSnapshotStrict(string $runId, SwarmPersistenceCipher $cipher): ?array
    {
        $sealed = $this->readSnapshot($runId);

        if ($sealed === null) {
            return null;
        }

        try {
            $plain = $cipher->openStrict($sealed);
        } catch (DecryptException $e) {
            throw new SwarmException(
                "Cold archive snapshot for run [{$runId}] could not be decrypted. ".
                'The APP_KEY may have been rotated after this run was graduated to cold storage. '.
                'Re-dispatch the run to recover.',
                previous: $e,
            );
        }

        return $plain !== null ? $this->decodeJson($plain, []) : null;
    }

    /**
     * Graduates hot events in [fromId, boundaryId) to cold storage and CAS-advances
     * the base pointer to boundaryId, all inside a single DB transaction.
     *
     * Ordering spine (never inverted):
     *   0. Pre-check: bail early if base_pointer already >= boundaryId.
     *   1. Write raw event rows to cold (idempotent via insertOrIgnore — no DELETE).
     *   2. Write / update snapshot payload (base_pointer left untouched until step 4).
     *   3. SET sealed_at on graduated hot events (serialises with appendVoidEdge locks).
     *   4. CAS advance base_pointer: WHERE base_pointer IS NULL OR base_pointer < boundaryId.
     *
     * The pre-check + insertOrIgnore pairing closes the lease-expiry race (#287 OG3):
     * if a prior compactor already graduated and reclaimed hot events, the pre-check
     * returns false before any writes, leaving cold data intact. Without the pre-check,
     * a DELETE+INSERT approach would wipe cold rows then find an empty hot log — data loss.
     * insertOrIgnore (backed by the unique index on (run_id, archive_type, sequence))
     * provides the secondary safety net for truly concurrent graduation attempts.
     *
     * Returns true when the CAS succeeds (exactly one row updated), false on a
     * concurrent race (CAS 0 rows — pointer already >= boundaryId). The caller
     * (SwarmCompactor) must check the return value; false means another compactor
     * won the race and the hot events were NOT reclaimed by this call.
     *
     * Does not DELETE from hot — that is the caller's responsibility via reclaim(),
     * invoked only after graduate() returns true.
     */
    public function graduate(string $runId, int $fromId, int $boundaryId, string $sealedSnapshot): bool
    {
        $coldTable = (string) $this->config->get('swarm.tables.cold_archives', 'swarm_cold_archives');
        $hotTable = (string) $this->config->get('swarm.tables.stream_events', 'swarm_stream_events');
        $now = Carbon::now('UTC');

        return (bool) $this->connection->transaction(function () use ($runId, $fromId, $boundaryId, $sealedSnapshot, $coldTable, $hotTable, $now): bool {
            // Step 0: pre-check — if base_pointer is already at or past boundaryId,
            // another compactor won the race and graduated this window. Bail early
            // before touching cold storage so we never wipe data then find an empty hot log.
            $currentBase = $this->connection->table($coldTable)
                ->where('run_id', $runId)
                ->where('archive_type', 'snapshot')
                ->value('base_pointer');

            if ($currentBase !== null && (int) $currentBase >= $boundaryId) {
                return false;
            }

            // Step 1: idempotent event row write via insertOrIgnore (no DELETE).
            // The unique index on (run_id, archive_type, sequence) ensures a concurrent
            // compactor's rows are silently skipped rather than duplicated.
            $hotQuery = $this->connection->table($hotTable)
                ->where('run_id', $runId)
                ->where('id', '>=', $fromId)
                ->where('id', '<', $boundaryId)
                ->orderBy('id');

            $insertRows = [];

            foreach ($hotQuery->cursor() as $record) {
                if ($record->event_type === 'swarm_causal_seal_barrier') {
                    continue;
                }

                $insertRows[] = [
                    'run_id' => $runId,
                    'archive_type' => 'event',
                    'sequence' => $record->id,
                    'payload' => $record->payload,
                    'base_pointer' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($insertRows) >= 200) {
                    $this->connection->table($coldTable)->insertOrIgnore($insertRows);
                    $insertRows = [];
                }
            }

            if ($insertRows !== []) {
                $this->connection->table($coldTable)->insertOrIgnore($insertRows);
            }

            // Step 2: snapshot row — update payload only; do NOT touch base_pointer here.
            // Resetting base_pointer to a placeholder (e.g. 0) creates a window where
            // TieredStreamEventStore readers see a stale boundary and route to an empty hot log.
            // The CAS in step 4 advances base_pointer atomically once cold data is durable.
            $hasSnapshot = $this->connection->table($coldTable)
                ->where('run_id', $runId)
                ->where('archive_type', 'snapshot')
                ->exists();

            if ($hasSnapshot) {
                $this->connection->table($coldTable)
                    ->where('run_id', $runId)
                    ->where('archive_type', 'snapshot')
                    ->update(['payload' => $sealedSnapshot, 'updated_at' => $now]);
            } else {
                $this->connection->table($coldTable)->insert([
                    'run_id' => $runId,
                    'archive_type' => 'snapshot',
                    'sequence' => null,
                    'payload' => $sealedSnapshot,
                    'base_pointer' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Step 3: seal graduated hot events so appendVoidEdge() rejects retroactive
            // void-edges. The lockForUpdate in appendVoidEdge serialises with this UPDATE.
            $this->connection->table($hotTable)
                ->where('run_id', $runId)
                ->where('id', '>=', $fromId)
                ->where('id', '<', $boundaryId)
                ->whereNull('sealed_at')
                ->update(['sealed_at' => $now, 'updated_at' => $now]);

            // Step 4: CAS advance — atomic, monotonic. Fails fast on race or re-run.
            $advanced = $this->connection->table($coldTable)
                ->where('run_id', $runId)
                ->where('archive_type', 'snapshot')
                ->where(function ($q) use ($boundaryId): void {
                    $q->whereNull('base_pointer')->orWhere('base_pointer', '<', $boundaryId);
                })
                ->update(['base_pointer' => $boundaryId, 'updated_at' => $now]);

            return $advanced === 1;
        });
    }

    /**
     * Deletes graduated hot events from the live event store.
     *
     * Called by SwarmCompactor only after graduate() returns true (CAS succeeded).
     * The ordering spine guarantees cold-durable + base-pointer-advanced before any
     * reclaim: a reader that sees id >= base_pointer on the hot side, or id < base_pointer
     * on the cold side, will always find its data.
     */
    public function reclaim(string $runId, int $boundaryId): void
    {
        $hotTable = (string) $this->config->get('swarm.tables.stream_events', 'swarm_stream_events');

        $this->connection->table($hotTable)
            ->where('run_id', $runId)
            ->where('id', '<', $boundaryId)
            ->delete();
    }

    protected function table(): Builder
    {
        return $this->connection->table(
            (string) $this->config->get('swarm.tables.cold_archives', 'swarm_cold_archives'),
        );
    }
}
