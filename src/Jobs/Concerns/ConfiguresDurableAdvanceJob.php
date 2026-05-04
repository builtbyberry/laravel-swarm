<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs\Concerns;

trait ConfiguresDurableAdvanceJob
{
    public function tries(): int
    {
        return max(1, (int) config('swarm.durable.job.tries', 3));
    }

    /**
     * Queue worker timeout (seconds) for one attempt. This is
     * `swarm.durable.step_timeout` plus `swarm.durable.job.timeout_margin_seconds`
     * so the worker outlives the orchestration step window by a small buffer.
     * It does not hard-cancel an in-flight provider call.
     */
    public function timeout(): int
    {
        $stepTimeout = max(1, (int) config('swarm.durable.step_timeout', 300));
        $margin = max(0, (int) config('swarm.durable.job.timeout_margin_seconds', 60));

        return $stepTimeout + $margin;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var array<int, int|string> $raw */
        $raw = config('swarm.durable.job.backoff_seconds', [10, 30, 60]);

        $seconds = [];
        foreach ($raw as $value) {
            $n = is_int($value) ? $value : (int) $value;
            if ($n > 0) {
                $seconds[] = $n;
            }
        }

        return $seconds !== [] ? $seconds : [10, 30, 60];
    }
}
