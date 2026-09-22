<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;

/** Optional atomic output/evidence persistence for durable stores. */
interface StoresDurableCitationEvidence
{
    /** @param array<string, mixed> $usage */
    public function markBranchCompletedWithCitations(string $runId, string $branchId, string $executionToken, string $output, array $usage, int $durationMs, CitationEvidence $evidence): void;

    public function storeHierarchicalNodeOutputWithCitations(string $runId, string $nodeId, string $output, int $ttlSeconds, CitationEvidence $evidence): void;

    public function hierarchicalNodeCitations(string $runId, string $nodeId): CitationEvidence;
}
