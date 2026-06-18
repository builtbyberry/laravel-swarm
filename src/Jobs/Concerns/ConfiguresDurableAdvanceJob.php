<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs\Concerns;

/**
 * @internal
 */
trait ConfiguresDurableAdvanceJob
{
    // timeout MUST be a property — Laravel reads $job->timeout directly via
    // getAttributeValue(); a timeout() method is never called. tries/backoff
    // stay methods because getJobTries/getJobBackoff are method-aware.
    public int $timeout;

    /**
     * Set $this->timeout from config. Call in the job constructor after
     * $this->enqueuedAtMs is assigned so the property is ready before the
     * job is serialized into the queue payload.
     */
    public function applyDurableAdvanceJobTimeout(): void
    {
        $this->timeout = max(1, (int) config('swarm.durable.step_timeout', 300))
            + max(0, (int) config('swarm.durable.job.timeout_margin_seconds', 60));
    }

    public function tries(): int
    {
        return max(1, (int) config('swarm.durable.job.tries', 3));
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
