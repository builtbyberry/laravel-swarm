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
        $baseBytes = strlen(json_encode((new CitationEvidence([], $evidence->status, $evidence->reasons))->toArray(), JSON_THROW_ON_ERROR)) + 32;
        $limited = false;
        foreach ($evidence->items as $item) {
            $key = json_encode([$item->runId, $item->stepIndex, $item->invocationId], JSON_THROW_ON_ERROR);
            $group = $groups[$key] ?? ['count' => 0, 'bytes' => $baseBytes];
            // Count encoded records and commas once; reserve the fixed limit marker.
            $size = $group['bytes'] + strlen(json_encode($item->toArray(), JSON_THROW_ON_ERROR)) + ($group['count'] > 0 ? 1 : 0);
            if ($group['count'] >= $count || $size > $bytes) {
                $limited = true;

                continue;
            }
            $groups[$key] = ['count' => $group['count'] + 1, 'bytes' => $size];
            $items[] = $item;
        }

        return $limited
            ? new CitationEvidence($items, CitationEvidence::PARTIAL, array_values(array_unique([...$evidence->reasons, 'limit'])))
            : $evidence;
    }
}
