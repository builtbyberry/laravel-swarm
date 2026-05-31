<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemorySnapshotted;
use BuiltByBerry\LaravelSwarm\Exceptions\SnapshotFrozenException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;

/**
 * Default {@see SnapshotsMemory} implementation backed by the
 * `swarm_memory_snapshots` table (added in #110).
 *
 * On {@see snapshot()} the recorder reads every Run-scoped memory entry for
 * the given `run_id` via the bound {@see SwarmMemory} facade, freezes the
 * order, and upserts the row keyed by `(run_id, step_index)`. Tool-call
 * appends rewrite only the `tool_calls` JSON column so the snapshot row
 * grows monotonically across the invocation without churning the payload.
 *
 * Scopes beyond `Run` (Conversation / Agent / Swarm) intentionally do not
 * land here yet — propagation policy in v0.10 decides which scopes a worker
 * sees, and the snapshot freeze must mirror whatever that policy returns.
 * Until that policy ships, freezing the Run-scoped view is the agent-visible
 * surface and matches what live runners observe today.
 *
 * @internal
 */
final class DatabaseMemorySnapshotRecorder implements SnapshotsMemory
{
    use InteractsWithJsonColumns;

    /**
     * Cached result of the one-time `swarm_memories` table precheck. `null`
     * means "not yet probed"; the first {@see snapshot()} call resolves it
     * via {@see Schema::hasTable()} and reuses the answer for the lifetime
     * of the instance so we don't pay a `SHOW TABLES` round-trip on every
     * agent invocation.
     */
    protected ?bool $memoryTableExists = null;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected SwarmMemory $memory,
        protected LoggerInterface $logger,
        protected Dispatcher $events,
    ) {}

    /**
     * @param  array<int, MemoryEntry>|null  $entries
     */
    public function snapshot(string $runId, int $stepIndex, ?array $entries = null): MemorySnapshot
    {
        // When the runner resolved the agent-visible view through the
        // propagation policy it passes it in `$entries`; freeze exactly those.
        // Otherwise fall back to gathering the Run-scoped view ourselves — the
        // back-compat path for callers that predate the parameter, which
        // preserves pre-v0.10 behaviour byte-for-byte.
        //
        // Note: the `ensureMemoryTableExists()` precheck below only guards this
        // internal fallback gather. On the runner path (`$entries` supplied) the
        // table-missing tolerance lives wherever the runner read memory — and in
        // practice a missing `swarm_memories` table fails the run at the first
        // memory access regardless, so nothing relied on this guard there.
        //
        // The companion `swarm_memories` table (issue #109) is required for
        // the Run-scoped read below. We probe its existence exactly once per
        // recorder instance via `Schema::hasTable()` and cache the result on
        // `$memoryTableExists`. When the precheck fails we log and persist
        // an empty entries list so the snapshot row itself still lands for
        // replay. When the precheck succeeds we delegate straight to the
        // memory facade and let any genuine `QueryException` (connection
        // drop, permission revocation, schema corruption, deadlock, …)
        // propagate — silently swallowing those would corrupt the audit
        // trail without surfacing the failure to the operator.
        if ($entries === null) {
            $entries = $this->ensureMemoryTableExists()
                ? $this->memory->all(MemoryScope::Run, $runId)
                : [];
        }

        $snapshot = MemorySnapshot::fromEntries($runId, $stepIndex, $entries, []);

        $this->persist($snapshot);

        return $snapshot;
    }

    public function appendToolCall(MemorySnapshot $snapshot, array $toolCall): MemorySnapshot
    {
        if ($snapshot->frozen) {
            throw SnapshotFrozenException::forStep($snapshot->runId, $snapshot->stepIndex);
        }

        $updated = $snapshot->withToolCall($toolCall);

        $this->table()
            ->where('run_id', $updated->runId)
            ->where('step_index', $updated->stepIndex)
            ->update([
                'tool_calls' => $this->encodeJson($updated->toolCalls),
                'updated_at' => CarbonImmutable::now('UTC'),
            ]);

        return $updated;
    }

    public function resetToolCalls(MemorySnapshot $snapshot): MemorySnapshot
    {
        // The reset persists eagerly so an interrupted retry can't leak the
        // previous attempt's partial tool-call record back into the canonical
        // row on its next try.
        $this->table()
            ->where('run_id', $snapshot->runId)
            ->where('step_index', $snapshot->stepIndex)
            ->update([
                'tool_calls' => $this->encodeJson([]),
                'updated_at' => CarbonImmutable::now('UTC'),
            ]);

        return $snapshot->withClearedToolCalls();
    }

    public function find(string $runId, int $stepIndex): ?MemorySnapshot
    {
        /** @var object|null $record */
        $record = $this->table()
            ->where('run_id', $runId)
            ->where('step_index', $stepIndex)
            ->first();

        if ($record === null) {
            return null;
        }

        return $this->hydrate($record);
    }

    public function allForRun(string $runId): array
    {
        $records = $this->table()
            ->where('run_id', $runId)
            ->orderBy('step_index')
            ->get();

        $snapshots = [];

        foreach ($records as $record) {
            $snapshots[] = $this->hydrate($record);
        }

        return $snapshots;
    }

    protected function hydrate(object $record): MemorySnapshot
    {
        $rawPayload = $record->payload ?? null;
        $rawToolCalls = $record->tool_calls ?? null;

        /** @var array<string, mixed> $payload */
        $payload = $this->decodeJson(is_string($rawPayload) ? $rawPayload : null, []);
        /** @var array<int, array<string, mixed>> $toolCalls */
        $toolCalls = $this->decodeJson(is_string($rawToolCalls) ? $rawToolCalls : null, []);

        return MemorySnapshot::fromPersisted(
            $payload,
            $toolCalls,
            recordedAt: $this->normalizeTimestamp($record->created_at ?? null),
            updatedAt: $this->normalizeTimestamp($record->updated_at ?? null),
        );
    }

    /**
     * Normalize a persisted row timestamp into an ISO-8601 string in UTC so
     * the inspector renders the same shape as the per-entry timestamps. The
     * raw value arrives as a datetime string from the query builder; tolerate
     * a `DateTimeInterface` too in case a driver casts the column.
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

    protected function persist(MemorySnapshot $snapshot): void
    {
        $now = CarbonImmutable::now('UTC');

        $encodedPayload = $this->encodeJson($snapshot->toPayloadArray());
        $encodedToolCalls = $this->encodeJson($snapshot->toolCalls);

        $this->table()->upsert(
            [[
                'run_id' => $snapshot->runId,
                'step_index' => $snapshot->stepIndex,
                'payload' => $encodedPayload,
                'tool_calls' => $encodedToolCalls,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['run_id', 'step_index'],
            ['payload', 'tool_calls', 'updated_at'],
        );

        // Dispatch after a successful upsert so listeners do not record bytes
        // for a snapshot that never landed.
        $this->events->dispatch(new MemorySnapshotted(
            runId: $snapshot->runId,
            stepIndex: $snapshot->stepIndex,
            snapshotId: $snapshot->runId.':'.$snapshot->stepIndex,
            bytes: strlen((string) $encodedPayload) + strlen((string) $encodedToolCalls),
            entryCount: count($snapshot->entries),
        ));
    }

    protected function table(): Builder
    {
        return $this->connection->table(
            (string) $this->config->get('swarm.tables.memory_snapshots', 'swarm_memory_snapshots'),
        );
    }

    /**
     * Probe the `swarm_memories` table once per recorder instance.
     *
     * Returns `true` when the table is present and the recorder can safely
     * read Run-scoped entries from the memory facade. Returns `false` when
     * the table is absent, in which case the snapshot persists with an
     * empty entries list and an info-level log line is emitted so operators
     * can spot misconfigured environments.
     */
    protected function ensureMemoryTableExists(): bool
    {
        if ($this->memoryTableExists !== null) {
            return $this->memoryTableExists;
        }

        $table = (string) $this->config->get('swarm.tables.memories', 'swarm_memories');
        $exists = Schema::connection($this->connection->getName())->hasTable($table);

        if (! $exists) {
            $this->logger->info(
                'laravel-swarm: memory table missing; snapshot will persist with empty entries',
                ['table' => $table, 'connection' => $this->connection->getName()],
            );
        }

        return $this->memoryTableExists = $exists;
    }
}
