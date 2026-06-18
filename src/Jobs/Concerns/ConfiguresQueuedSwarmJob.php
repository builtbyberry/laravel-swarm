<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs\Concerns;

/**
 * @internal
 */
trait ConfiguresQueuedSwarmJob
{
    // Laravel reads the $timeout PROPERTY via getAttributeValue() — it never calls a timeout() method.
    // tries() stays a method because Laravel's getJobTries() adds an explicit method_exists() check;
    // there is no equivalent getJobTimeout(), so a timeout() method would be silently ignored.
    public ?int $timeout = null;

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
     * Apply the configured queue timeout to the $timeout property so Laravel's
     * queue payload builder picks it up. Must be called in the job constructor.
     * Timeout is inherited from the worker's --timeout by default; set
     * SWARM_QUEUE_TIMEOUT to impose an explicit ceiling.
     */
    protected function applyQueuedSwarmJobTimeout(): void
    {
        $t = config('swarm.queue.timeout');
        $this->timeout = $t === null ? null : (int) $t;
    }
}
