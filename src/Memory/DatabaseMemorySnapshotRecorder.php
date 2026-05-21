<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;

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

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected SwarmMemory $memory,
    ) {}

    public function snapshot(string $runId, int $stepIndex): MemorySnapshot
    {
        // The companion `swarm_memories` table (issue #109) may not be
        // migrated yet — for example during the 0.9.0 staged rollout where
        // the entries-schema and snapshots-schema migrations land in
        // separate PRs. Treat a missing memory table as "no Run-scoped
        // entries" rather than failing every agent invocation; the snapshot
        // row itself still persists so replay sees the empty view.
        try {
            $entries = $this->memory->all(MemoryScope::Run, $runId);
        } catch (QueryException) {
            $entries = [];
        }

        $snapshot = MemorySnapshot::fromEntries($runId, $stepIndex, $entries, []);

        $this->persist($snapshot);

        return $snapshot;
    }

    public function appendToolCall(MemorySnapshot $snapshot, array $toolCall): MemorySnapshot
    {
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

        $rawPayload = $record->payload ?? null;
        $rawToolCalls = $record->tool_calls ?? null;

        /** @var array<string, mixed> $payload */
        $payload = $this->decodeJson(is_string($rawPayload) ? $rawPayload : null, []);
        /** @var array<int, array<string, mixed>> $toolCalls */
        $toolCalls = $this->decodeJson(is_string($rawToolCalls) ? $rawToolCalls : null, []);

        return MemorySnapshot::fromPersisted($payload, $toolCalls);
    }

    protected function persist(MemorySnapshot $snapshot): void
    {
        $now = CarbonImmutable::now('UTC');

        $this->table()->upsert(
            [[
                'run_id' => $snapshot->runId,
                'step_index' => $snapshot->stepIndex,
                'payload' => $this->encodeJson($snapshot->toPayloadArray()),
                'tool_calls' => $this->encodeJson($snapshot->toolCalls),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['run_id', 'step_index'],
            ['payload', 'tool_calls', 'updated_at'],
        );
    }

    protected function table(): Builder
    {
        return $this->connection->table(
            (string) $this->config->get('swarm.tables.memory_snapshots', 'swarm_memory_snapshots'),
        );
    }
}
