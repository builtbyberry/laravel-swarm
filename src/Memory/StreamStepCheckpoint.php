<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;

/**
 * Immutable streamed-step checkpoint carrying output and usage for the
 * `(run_id, step_index)` natural key.
 *
 * This value object retains the supplied output string and usage counters
 * without transformation, with optional persistence timestamps. It accepts a
 * nullable output and does not decide whether a checkpoint is resumable.
 *
 * Checkpoint selection and resumed execution are owned by
 * [SequentialRunner](../Runners/SequentialRunner.php); memory snapshots
 * have their own shape in {@see MemorySnapshot}.
 */
final readonly class StreamStepCheckpoint
{
    public CitationEvidence $citationEvidence;

    /**
     * @param  array<string, int|null>  $usage
     */
    public function __construct(
        public string $runId,
        public int $stepIndex,
        public ?string $output,
        public array $usage = [],
        public ?string $recordedAt = null,
        public ?string $updatedAt = null,
        ?CitationEvidence $citationEvidence = null,
        public ?NativeStepResult $nativeResult = null,
    ) {
        $this->citationEvidence = $citationEvidence ?? new CitationEvidence;
    }

    /**
     * Rehydrate a checkpoint from the persisted columns.
     *
     * `$recordedAt` / `$updatedAt` are the persisted row timestamps as ISO-8601
     * strings, surfaced for operator tooling; pass null when the caller has no
     * row timestamps to carry.
     *
     * @param  array<string, int|null>  $usage
     */
    public static function fromPersisted(
        string $runId,
        int $stepIndex,
        ?string $output,
        array $usage,
        ?string $recordedAt = null,
        ?string $updatedAt = null,
        ?CitationEvidence $citationEvidence = null,
        ?NativeStepResult $nativeResult = null,
    ): self {
        return new self(
            citationEvidence: $citationEvidence,
            runId: $runId,
            stepIndex: $stepIndex,
            output: $output,
            usage: $usage,
            recordedAt: $recordedAt,
            updatedAt: $updatedAt,
            nativeResult: $nativeResult,
        );
    }
}
