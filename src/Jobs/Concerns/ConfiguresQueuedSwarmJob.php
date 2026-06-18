<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs\Concerns;

/**
 * @internal
 */
trait ConfiguresQueuedSwarmJob
{
    /**
     * Queued swarm runs are attempted once by default, regardless of the worker's
     * global --tries. A retry restarts the entire swarm run from step 0, re-dispatching
     * tools and re-spending LLM tokens. Set swarm.queue.tries > 1 only when your swarms
     * are idempotent and the token cost of a full restart is acceptable.
     */
    public function tries(): int
    {
        return (int) config('swarm.queue.tries', 1);
    }

    /**
     * Timeout is inherited from the worker's --timeout by default. A low package-level
     * cap would kill legitimately long LLM runs — the safety concern for queued swarms
     * is retries, not timeout. Set SWARM_QUEUE_TIMEOUT to impose an explicit ceiling.
     */
    public function timeout(): ?int
    {
        $t = config('swarm.queue.timeout');

        return $t === null ? null : (int) $t;
    }
}
