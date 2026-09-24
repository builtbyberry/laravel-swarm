<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Concerns;

use Laravel\Ai\Responses\AgentResponse;

/**
 * @internal
 */
trait MergesAgentUsage
{
    /**
     * Combine aggregates. An empty aggregate represents no contributors.
     *
     * @param  array<string, mixed>  $accumulated
     * @param  array<string, mixed>  $next
     * @return array<string, int|null>
     */
    protected function mergeUsage(array $accumulated, array $next): array
    {
        if ($accumulated === []) {
            return $next === [] ? [] : $this->normalizeUsageReport($next);
        }

        if ($next === []) {
            return $this->normalizeUsageReport($accumulated);
        }

        $accumulated = $this->normalizeUsageReport($accumulated);
        $next = $this->normalizeUsageReport($next);

        if (array_keys($accumulated) !== array_keys($next)) {
            return $this->normalizeUsageReport([]);
        }

        foreach ($accumulated as $key => $value) {
            $other = $next[$key];
            $sum = $value !== null && $other !== null ? $value + $other : null;
            // PHP promotes overflowing integer addition to float. Accounting
            // outside the representable range remains unavailable.
            $accumulated[$key] = is_int($sum) ? $sum : null;
        }

        return $accumulated;
    }

    /**
     * Fold one real invocation, including an invocation with no usage report.
     *
     * @param  array<string, mixed>  $accumulated
     * @param  array<string, mixed>  $report
     * @return array<string, int|null>
     */
    protected function mergeUsageReport(array $accumulated, array $report): array
    {
        return $this->mergeUsage($accumulated, $this->normalizeUsageReport($report));
    }

    /**
     * Normalize accounting without rewriting the original invocation evidence.
     * Both primary name pairs identify mixed or unavailable accounting.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, int|null>
     */
    protected function normalizeUsageReport(array $report): array
    {
        $native = array_key_exists('input_tokens', $report) || array_key_exists('output_tokens', $report);
        $legacy = array_key_exists('prompt_tokens', $report) || array_key_exists('completion_tokens', $report);
        $subsets = ['cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'];

        if ($native === $legacy) {
            return array_fill_keys(['input_tokens', 'output_tokens', 'prompt_tokens', 'completion_tokens', ...$subsets], null);
        }

        $keys = [...($native ? ['input_tokens', 'output_tokens'] : ['prompt_tokens', 'completion_tokens']), ...$subsets];
        $normalized = [];
        foreach ($keys as $key) {
            $value = $report[$key] ?? null;
            $normalized[$key] = is_int($value) && $value >= 0 ? $value : null;
        }

        return $normalized;
    }

    /**
     * @return array<string, int|null>
     */
    protected function usageFromResponse(mixed $response): array
    {
        if ($response instanceof AgentResponse) {
            return $response->usage->toArray();
        }

        return [];
    }
}
