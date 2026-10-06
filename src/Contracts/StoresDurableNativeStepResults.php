<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/** Optional atomic native-result capability for durable coordination stores. */
interface StoresDurableNativeStepResults extends StoresDurableCitationEvidence
{
    public function storeHierarchicalNodeOutputWithNativeResult(
        string $runId,
        string $nodeId,
        string $output,
        int $ttlSeconds,
        CitationEvidence $evidence,
        NativeStepResult $nativeResult,
    ): void;

    /** @param array<string, int|null> $usage */
    public function markBranchCompletedWithNativeResult(
        string $runId,
        string $branchId,
        string $executionToken,
        string $output,
        array $usage,
        int $durationMs,
        CitationEvidence $evidence,
        NativeStepResult $nativeResult,
    ): void;

    /**
     * @param  array<string, mixed>  $routeCursor
     * @param  array<string, mixed>|null  $routePlan
     * @param  array{node_id: string, output: string, citation_evidence?: array<string, mixed>, native_result_status?: string, native_result?: array<string, mixed>}|null  $nodeOutput
     * @param  array<int, string>  $clearBranchParentNodeIds
     */
    public function checkpointHierarchicalStepWithNativeResult(
        string $runId,
        string $executionToken,
        int $nextStepIndex,
        RunContext $context,
        int $ttlSeconds,
        array $routeCursor,
        ?array $routePlan = null,
        ?array $nodeOutput = null,
        ?int $totalSteps = null,
        array $clearBranchParentNodeIds = [],
    ): void;
}
