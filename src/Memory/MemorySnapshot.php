<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;

/**
 * Immutable, serializable freeze of the memory view an agent saw at the
 * moment a runner invoked it.
 *
 * A snapshot is identified by the natural key `(run_id, step_index)` shared
 * with `swarm_run_steps`, and carries two payloads:
 *
 * - `entries` — the agent-visible memory entries at invocation time. Each row
 *   captures `scope`, `scope_id`, `key`, `value`, and `metadata` plus optional
 *   ISO-8601 `created_at` / `updated_at` strings so a replay can rebuild a
 *   {@see MemoryEntry} byte-identical to the original.
 * - `toolCalls` — input/output pairs for every tool the agent called during
 *   its invocation. Streamed runs (issue #115) replay byte-identical from
 *   this list.
 *
 * Snapshots are produced by implementations of
 * {@see SnapshotsMemory} and consumed by
 * the durable replay path (issue #112) and the inspector CLI (v0.10.0 issue
 * #122). They are intentionally plain-data so they round-trip through any
 * JSON column without driver-specific encoding.
 */
final readonly class MemorySnapshot
{
    /**
     * @param  array<int, array{scope: string, scope_id: string, key: string, value: mixed, metadata: array<string, mixed>, created_at: string|null, updated_at: string|null}>  $entries
     * @param  array<int, array{name: string, arguments: array<string, mixed>, result: mixed, id?: string|null, result_id?: string|null}>  $toolCalls
     *
     * `$frozen` records the canonical-replay invariant: true means the snapshot
     * was loaded from persistence as the canonical record of a completed step
     * and any attempt to mutate it (via
     * {@see SnapshotsMemory::appendToolCall()}) must raise loudly. False means
     * the snapshot is either freshly frozen for an in-flight invocation or
     * being rebuilt during a mid-flight retry — both legitimate write paths.
     *
     * Constructors that produce fresh-snapshot instances (`fromEntries()`,
     * implementations of {@see SnapshotsMemory::snapshot()}) leave `$frozen`
     * false. The read-side hydrator (`fromPersisted()`) and any other
     * read-only producer must opt in to `$frozen = true` so the contract
     * layer can defend the canonical record from drift-time mutation.
     */
    public function __construct(
        public string $runId,
        public int $stepIndex,
        public array $entries,
        public array $toolCalls = [],
        public bool $frozen = false,
    ) {}

    /**
     * Build a snapshot from a list of live {@see MemoryEntry} instances.
     *
     * The order of `$entries` is preserved as the snapshot's canonical order.
     * Replay reads the array straight back without re-sorting.
     *
     * @param  array<int, MemoryEntry>  $entries
     * @param  array<int, array{name: string, arguments: array<string, mixed>, result: mixed, id?: string|null, result_id?: string|null}>  $toolCalls
     */
    public static function fromEntries(string $runId, int $stepIndex, array $entries, array $toolCalls = []): self
    {
        return new self(
            runId: $runId,
            stepIndex: $stepIndex,
            entries: array_values(array_map(static fn (MemoryEntry $entry): array => [
                'scope' => $entry->scope->value,
                'scope_id' => $entry->scopeId,
                'key' => $entry->key,
                'value' => $entry->value,
                'metadata' => $entry->metadata,
                'created_at' => $entry->createdAt?->toIso8601String(),
                'updated_at' => $entry->updatedAt?->toIso8601String(),
            ], $entries)),
            toolCalls: array_values($toolCalls),
        );
    }

    /**
     * Return a copy of this snapshot with `$toolCall` appended to the
     * `toolCalls` list. The original instance is left untouched.
     *
     * @param  array{name: string, arguments: array<string, mixed>, result: mixed, id?: string|null, result_id?: string|null}  $toolCall
     */
    public function withToolCall(array $toolCall): self
    {
        return new self(
            runId: $this->runId,
            stepIndex: $this->stepIndex,
            entries: $this->entries,
            toolCalls: [...$this->toolCalls, $toolCall],
            frozen: $this->frozen,
        );
    }

    /**
     * Return a copy of this snapshot with `toolCalls` reset to an empty list.
     *
     * Used by the mid-flight retry path in the durable runner: the original
     * worker crashed after the snapshot was frozen but before the step
     * completed, leaving a partial `tool_calls` record. The retry preserves
     * the frozen memory view (determinism guarantee) but rebuilds tool calls
     * from scratch.
     *
     * The returned snapshot is unfrozen so subsequent
     * {@see SnapshotsMemory::appendToolCall()} calls succeed. Carries no
     * other state change.
     */
    public function withClearedToolCalls(): self
    {
        return new self(
            runId: $this->runId,
            stepIndex: $this->stepIndex,
            entries: $this->entries,
            toolCalls: [],
            frozen: false,
        );
    }

    /**
     * Return a copy with the entire `toolCalls` list replaced.
     *
     * @param  array<int, array{name: string, arguments: array<string, mixed>, result: mixed, id?: string|null, result_id?: string|null}>  $toolCalls
     */
    public function withToolCalls(array $toolCalls): self
    {
        return new self(
            runId: $this->runId,
            stepIndex: $this->stepIndex,
            entries: $this->entries,
            toolCalls: array_values($toolCalls),
            frozen: $this->frozen,
        );
    }

    /**
     * The `payload` JSON column shape persisted to `swarm_memory_snapshots`.
     *
     * @return array{run_id: string, step_index: int, entries: array<int, array{scope: string, scope_id: string, key: string, value: mixed, metadata: array<string, mixed>, created_at: string|null, updated_at: string|null}>}
     */
    public function toPayloadArray(): array
    {
        return [
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'entries' => $this->entries,
        ];
    }

    /**
     * Rehydrate a snapshot from the persisted `payload` + `tool_calls` JSON
     * columns. The shape mirrors what {@see toPayloadArray()} and
     * {@see toolCalls} emit on persist.
     *
     * `$frozen` defaults to true because the typical caller is the read-side
     * `find()` path that loads a canonical record. Mid-flight retry callers
     * explicitly pass `frozen: false` so they can rebuild tool calls without
     * tripping the canonical-record guard.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    public static function fromPersisted(array $payload, array $toolCalls, bool $frozen = true): self
    {
        /** @var array<int, array{scope: string, scope_id: string, key: string, value: mixed, metadata: array<string, mixed>, created_at: string|null, updated_at: string|null}> $entries */
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];

        /** @var array<int, array{name: string, arguments: array<string, mixed>, result: mixed, id?: string|null, result_id?: string|null}> $normalizedToolCalls */
        $normalizedToolCalls = array_values($toolCalls);

        return new self(
            runId: (string) ($payload['run_id'] ?? ''),
            stepIndex: (int) ($payload['step_index'] ?? 0),
            entries: $entries,
            toolCalls: $normalizedToolCalls,
            frozen: $frozen,
        );
    }
}
