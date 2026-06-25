<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

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
            yield SwarmStreamEvent::fromArray($this->decodeJson($record->payload, []));
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

    protected function table(): Builder
    {
        return $this->connection->table(
            (string) $this->config->get('swarm.tables.cold_archives', 'swarm_cold_archives'),
        );
    }
}
