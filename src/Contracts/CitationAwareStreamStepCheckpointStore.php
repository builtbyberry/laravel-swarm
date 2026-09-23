<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;

interface CitationAwareStreamStepCheckpointStore extends StreamStepCheckpointStore
{
    /** @param array<string, int|null> $usage */
    public function recordWithCitations(string $runId, int $stepIndex, string $output, array $usage, CitationEvidence $evidence): void;
}
