<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;

/**
 * Freezes the memory view an agent will see at invocation, persists it, and
 * exposes hooks for runners to append tool-call input/output pairs observed
 * during the invocation.
 *
 * Resolved from the container as a singleton. Each of Swarm's four runners
 * (SequentialRunner, ParallelRunner, HierarchicalRunner, DurableBranchAdvancer)
 * calls {@see snapshot()} immediately before `$agent->prompt(...)` /
 * `$agent->stream(...)` so the persisted view is byte-identical to what the
 * agent actually saw. Replay (issue #112) reads back via
 * {@see find()} instead of re-querying live {@see SwarmMemory}.
 *
 * Implementations must:
 *
 * - Capture the agent-visible memory view atomically — no entries written
 *   after the freeze can leak into the snapshot.
 * - Persist the snapshot to the `swarm_memory_snapshots` table (or an
 *   equivalent backend) keyed by `(run_id, step_index)`.
 * - Accept tool-call appends via {@see appendToolCall()} and update the
 *   `tool_calls` JSON column in place, so streamed runs reach a single final
 *   row containing every input/output pair observed during the invocation.
 *
 * Implementations are free to no-op when the runtime has no SwarmMemory bound
 * (e.g. tests that never wire memory). The contract guarantees the returned
 * {@see MemorySnapshot} reflects whatever was frozen, even if empty.
 */
interface SnapshotsMemory
{
    /**
     * Freeze the agent-visible memory view for `(run_id, step_index)` and
     * persist it with an empty `tool_calls` list. Returns the value object
     * that subsequent {@see appendToolCall()} calls must reference.
     */
    public function snapshot(string $runId, int $stepIndex): MemorySnapshot;

    /**
     * Append one tool-call input/output pair to the snapshot for
     * `(run_id, step_index)` and re-persist the `tool_calls` JSON column.
     *
     * Returns the updated snapshot with the new tool call included. Callers
     * should treat the original snapshot as stale after this call.
     *
     * @param  array{name: string, arguments: array<string, mixed>, result: mixed, id?: string|null, result_id?: string|null}  $toolCall
     */
    public function appendToolCall(MemorySnapshot $snapshot, array $toolCall): MemorySnapshot;

    /**
     * Look up a previously persisted snapshot by its natural key. Returns
     * null when no snapshot was recorded for that step.
     */
    public function find(string $runId, int $stepIndex): ?MemorySnapshot;
}
