<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

/**
 * Immutable record of a completed non-final streamed step, used to resume a
 * crashed `stream()` run idempotently (issue #202).
 *
 * A {@see MemorySnapshot} freezes the
 * agent-visible memory *view* and tool calls for `(run_id, step_index)`, but
 * deliberately does NOT carry the agent's *output* string or usage. Those are
 * the only data a resume needs to skip an already-completed non-final step:
 * the raw output is fed verbatim into the next step's prompt, and the usage is
 * re-merged so the run total is byte-identical. This value object carries
 * exactly that pair, keyed by the same `(run_id, step_index)` natural key.
 *
 * The stored `$output` is the RAW, un-redacted, un-truncated value the original
 * step produced — byte-identity of the downstream prompt depends on it. The
 * checkpoint is operational resume state (parallel to `swarm_contexts.input`),
 * not a capture/evidence surface.
 *
 * `$output` is nullable purely so the read-side hydrator can distinguish a
 * completed step (non-null) from a row that does not exist — the store contract
 * never returns an incomplete checkpoint, so a checkpoint handed to a caller
 * always has a non-null output.
 */
final readonly class StreamStepCheckpoint
{
    /**
     * @param  array<string, int>  $usage
     */
    public function __construct(
        public string $runId,
        public int $stepIndex,
        public ?string $output,
        public array $usage = [],
        public ?string $recordedAt = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * Rehydrate a checkpoint from the persisted columns.
     *
     * `$recordedAt` / `$updatedAt` are the persisted row timestamps as ISO-8601
     * strings, surfaced for operator tooling; pass null when the caller has no
     * row timestamps to carry.
     *
     * @param  array<string, int>  $usage
     */
    public static function fromPersisted(
        string $runId,
        int $stepIndex,
        ?string $output,
        array $usage,
        ?string $recordedAt = null,
        ?string $updatedAt = null,
    ): self {
        return new self(
            runId: $runId,
            stepIndex: $stepIndex,
            output: $output,
            usage: $usage,
            recordedAt: $recordedAt,
            updatedAt: $updatedAt,
        );
    }
}
