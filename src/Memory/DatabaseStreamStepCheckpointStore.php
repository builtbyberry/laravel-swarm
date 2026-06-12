<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\StreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Default {@see StreamStepCheckpointStore} implementation backed by the
 * `swarm_stream_step_checkpoints` table (added for issue #202).
 *
 * {@see record()} upserts the completed step's raw output + usage keyed by
 * `(run_id, step_index)`. {@see find()} reads it back, returning null unless the
 * row exists AND carries a non-null output — so a reserved-but-crashed row reads
 * as absent and the step re-executes.
 *
 * @internal
 */
final class DatabaseStreamStepCheckpointStore implements StreamStepCheckpointStore
{
    use InteractsWithJsonColumns;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
    ) {}

    public function record(string $runId, int $stepIndex, string $output, array $usage): void
    {
        $now = CarbonImmutable::now('UTC');

        $this->table()->upsert(
            [[
                'run_id' => $runId,
                'step_index' => $stepIndex,
                'output' => $output,
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
        // so the runner re-executes rather than rehydrating a partial step.
        if (! is_string($output)) {
            return null;
        }

        /** @var array<string, int> $usage */
        $usage = $this->decodeJson(is_string($record->usage ?? null) ? $record->usage : null, []);

        return StreamStepCheckpoint::fromPersisted(
            runId: $runId,
            stepIndex: $stepIndex,
            output: $output,
            usage: $usage,
            recordedAt: $this->normalizeTimestamp($record->created_at ?? null),
            updatedAt: $this->normalizeTimestamp($record->updated_at ?? null),
        );
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
