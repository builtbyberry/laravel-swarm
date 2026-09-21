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
     * Return the configured attempt limit for jobs using this trait.
     *
     * See [SwarmRunner](../../Runners/SwarmRunner.php) for execution and
     * duplicate handling, and [queue retry guidance](../../../docs/execution-modes.md#queue-retry--timeout)
     * for the operational limits of increasing this setting.
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
