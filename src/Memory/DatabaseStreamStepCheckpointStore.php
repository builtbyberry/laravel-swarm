<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\StreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;

/**
 * Default {@see StreamStepCheckpointStore} implementation backed by the
 * `swarm_stream_step_checkpoints` table (added for issue #202).
 *
 * {@see record()} upserts the completed step's output + usage keyed by
 * `(run_id, step_index)`. {@see find()} reads it back, returning null unless the
 * row exists AND carries a non-null output — so a reserved-but-crashed row reads
 * as absent and the step re-executes.
 *
 * The `output` column is sealed through {@see SwarmPersistenceCipher} on write
 * and opened on read, exactly as `DatabaseContextStore` (`input`) and
 * `DatabaseRunHistoryStore` (`output`) do — the checkpoint stores raw agent
 * output, so it inherits the same at-rest encryption discipline. `usage` is a
 * plain JSON column (token counts, never sealed, matching the siblings).
 *
 * Like {@see DatabaseMemorySnapshotRecorder}, the store probes its own table
 * once and degrades to a no-op when it is absent (e.g. the database driver is
 * configured but the migration has not run yet), so a deploy-before-migrate
 * window never throws mid-stream — resume simply falls back to re-execution.
 *
 * @internal
 */
final class DatabaseStreamStepCheckpointStore implements StreamStepCheckpointStore
{
    use InteractsWithJsonColumns;

    /**
     * Cached result of the one-time table precheck. `null` means "not yet
     * probed"; the first {@see record()} / {@see find()} call resolves it via
     * {@see Schema::hasTable()} and reuses the answer for the lifetime of the
     * instance.
     */
    protected ?bool $tableExists = null;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected SwarmPersistenceCipher $cipher,
        protected LoggerInterface $logger,
    ) {}

    public function record(string $runId, int $stepIndex, string $output, array $usage): void
    {
        if (! $this->ensureTableExists()) {
            return;
        }

        $now = CarbonImmutable::now('UTC');

        $this->table()->upsert(
            [[
                'run_id' => $runId,
                'step_index' => $stepIndex,
                'output' => $this->cipher->seal($output),
                'usage' => $this->encodeJson($usage),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['run_id', 'step_index'],
            ['output', 'usage', 'updated_at'],
        );
    }

    public function find(string $runId, int $stepIndex): ?StreamStepCheckpoint
    {
        if (! $this->ensureTableExists()) {
            return null;
        }

        /** @var object|null $record */
        $record = $this->table()
            ->where('run_id', $runId)
            ->where('step_index', $stepIndex)
            ->first();

        if ($record === null) {
            return null;
        }

        $output = $record->output ?? null;

        // A row without an output is not a completed step — treat it as absent
        // so the runner re-executes rather than rehydrating a partial step. The
        // is_string check runs BEFORE open() so a NULL column stays the marker.
        if (! is_string($output)) {
            return null;
        }

        // A checkpoint is a best-effort, recomputable resume optimisation — NOT
        // an evidence surface — so it decrypts with the policy-INDEPENDENT
        // openStrict() rather than open() (which would apply the operator's
        // decrypt-failure display policy and force us to guess success from the
        // plaintext's bytes). If the value can't be decrypted (rotated/wrong
        // APP_KEY), we return null → the runner re-executes the step (always
        // correct), under every decrypt-failure policy, and never feeds a
        // sealed/empty value downstream. A decrypted plaintext that legitimately
        // begins with `sw0:` round-trips cleanly (no false re-execution).
        try {
            $opened = $this->cipher->openStrict($output);
        } catch (DecryptException) {
            // debug-level: a rotated key fails every checkpoint, so this would
            // flood at higher levels — and under the default null_with_log policy
            // the evidence read path (run history) already warns about the
            // APP_KEY mismatch.
            $this->logger->debug(
                'laravel-swarm: stream step checkpoint output could not be decrypted; the step will re-execute on resume.',
                ['run_id' => $runId, 'step_index' => $stepIndex],
            );

            return null;
        }

        /** @var array<string, int> $usage */
        $usage = $this->decodeJson(is_string($record->usage ?? null) ? $record->usage : null, []);

        return StreamStepCheckpoint::fromPersisted(
            runId: $runId,
            stepIndex: $stepIndex,
            output: $opened,
            usage: $usage,
            recordedAt: $this->normalizeTimestamp($record->created_at ?? null),
            updatedAt: $this->normalizeTimestamp($record->updated_at ?? null),
        );
    }

    /**
     * Probe the `swarm_stream_step_checkpoints` table once per store instance.
     *
     * Returns true when the table is present. Returns false when it is absent,
     * in which case {@see record()} is a no-op and {@see find()} returns null —
     * resume degrades to re-execution (the pre-#202 behaviour) instead of
     * throwing a QueryException on a previously-working stream. Mirrors
     * {@see DatabaseMemorySnapshotRecorder::ensureMemoryTableExists()}.
     */
    protected function ensureTableExists(): bool
    {
        if ($this->tableExists !== null) {
            return $this->tableExists;
        }

        $table = (string) $this->config->get('swarm.tables.stream_step_checkpoints', 'swarm_stream_step_checkpoints');
        $exists = Schema::connection($this->connection->getName())->hasTable($table);

        if (! $exists) {
            $this->logger->info(
                'laravel-swarm: stream step checkpoint table missing; multi-step resume is disabled (steps re-execute on resume)',
                ['table' => $table, 'connection' => $this->connection->getName()],
            );
        }

        return $this->tableExists = $exists;
    }

    /**
     * Normalize a persisted row timestamp into an ISO-8601 string in UTC,
     * matching {@see DatabaseMemorySnapshotRecorder::normalizeTimestamp()}.
     */
    protected function normalizeTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value, 'UTC')->toIso8601String();
        }

        return null;
    }

    protected function table(): Builder
    {
        return $this->connection->table(
            (string) $this->config->get('swarm.tables.stream_step_checkpoints', 'swarm_stream_step_checkpoints'),
        );
    }
}
