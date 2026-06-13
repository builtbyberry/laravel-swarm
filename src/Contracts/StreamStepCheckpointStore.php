<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Memory\StreamStepCheckpoint;

/**
 * Persists the output + usage of a completed non-final streamed step so an
 * abandoned `stream()` run can resume idempotently (issue #202).
 *
 * This is the non-durable analogue of the durable runtime's per-node output
 * store (`swarm_durable_node_outputs`): it complements
 * {@see SnapshotsMemory} (which freezes the agent-visible memory *view* + tool
 * calls) by recording the one datum the snapshot omits — the step's raw output
 * string, the value fed into the next step's prompt — plus its usage. On resume
 * the runner reads this back to skip the step's agent invocation entirely (no
 * provider re-invoke, no tool side-effect re-fire) and rehydrate the downstream
 * prompt byte-identically.
 *
 * Resolved from the container as a singleton, in lockstep with
 * {@see SnapshotsMemory}: the database implementation is wired when
 * `swarm.persistence.driver` (or the `swarm.memory.driver` override) is
 * `database`, and a no-op implementation otherwise — a resume needs both the
 * snapshot and the checkpoint to be database-backed.
 *
 * Implementations must:
 *
 * - Persist to the `swarm_stream_step_checkpoints` table (or an equivalent
 *   backend) keyed by `(run_id, step_index)`, upserting so a re-run of the same
 *   step overwrites rather than duplicating.
 * - Store the RAW output value untouched — no capture/redaction/truncation;
 *   byte-identity of the resumed downstream prompt depends on it.
 * - Guarantee {@see find()} returns null unless the checkpoint is COMPLETE
 *   (a non-null output was recorded). A row that exists only because a step was
 *   reserved, or that crashed before its output was written, must read as
 *   absent so the runner re-executes that step.
 */
interface StreamStepCheckpointStore
{
    /**
     * Record (upsert) the completed checkpoint for `(run_id, step_index)`.
     *
     * Called only after the step's agent invocation, guardrails, and step
     * recording have all succeeded — the write is the completion marker. The
     * `$output` is the raw, un-redacted value the step produced.
     *
     * @param  array<string, int>  $usage
     */
    public function record(string $runId, int $stepIndex, string $output, array $usage): void;

    /**
     * Look up a COMPLETED checkpoint by its natural key. Returns null when no
     * checkpoint was recorded for that step, or when the recorded row is not
     * complete (no output) — both mean "re-execute this step".
     */
    public function find(string $runId, int $stepIndex): ?StreamStepCheckpoint;
}
