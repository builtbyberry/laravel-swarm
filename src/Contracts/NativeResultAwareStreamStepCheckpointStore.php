<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Memory\StreamStepCheckpoint;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;

/** Optional atomic native-result checkpoint capability. */
interface NativeResultAwareStreamStepCheckpointStore extends StreamStepCheckpointStore
{
    /** @param array<string, int|null> $usage */
    public function recordWithNativeResult(
        string $runId,
        int $stepIndex,
        string $output,
        array $usage,
        CitationEvidence $evidence,
        NativeStepResult $nativeResult,
    ): void;

    public function findWithNativeResult(string $runId, int $stepIndex): ?StreamStepCheckpoint;
}
