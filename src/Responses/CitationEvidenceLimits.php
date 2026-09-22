<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use Illuminate\Contracts\Config\Repository;

/** @internal */
final class CitationEvidenceLimits
{
    public function __construct(private Repository $config) {}

    public function apply(CitationEvidence $evidence): CitationEvidence
    {
        $count = max(0, (int) $this->config->get('swarm.citations.max_count', 256));
        $bytes = max(0, (int) $this->config->get('swarm.citations.max_bytes', 262144));
        if (! in_array($evidence->status, [CitationEvidence::AVAILABLE, CitationEvidence::PARTIAL], true)) {
            return $evidence;
        }
        $items = $groups = [];
        $limited = false;
        foreach ($evidence->items as $item) {
            $key = json_encode([$item->runId, $item->stepIndex, $item->invocationId], JSON_THROW_ON_ERROR);
            $group = $groups[$key] ?? [];
            $candidate = new CitationEvidence([...$group, $item], $evidence->status, $evidence->reasons);
            // Reserve the fixed limit marker so the final per-invocation envelope fits.
            $size = strlen(json_encode($candidate->toArray(), JSON_THROW_ON_ERROR)) + 32;
            if (count($group) >= $count || $size > $bytes) {
                $limited = true;

                continue;
            }
            $groups[$key] = [...$group, $item];
            $items[] = $item;
        }

        return $limited
            ? new CitationEvidence($items, CitationEvidence::PARTIAL, array_values(array_unique([...$evidence->reasons, 'limit'])))
            : $evidence;
    }
}
