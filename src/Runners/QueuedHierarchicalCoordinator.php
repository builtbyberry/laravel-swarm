<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * @internal Coordinates queued hierarchical swarms that use multi-worker parallel route segments.
 */
class QueuedHierarchicalCoordinator
{
    public function __construct(
        protected Application $application,
        protected ConfigRepository $config,
        protected DurableSwarmManager $durableSwarmManager,
        protected HierarchicalRunner $hierarchicalRunner,
    ) {}

    /**
     * First segment of a queued hierarchical run (InvokeSwarm entry).
     *
     * @return SwarmResponse|null null when deferred to parallel branch jobs (boundary handled internally)
     */
    public function runInvokeSegment(SwarmExecutionState $state): ?SwarmResponse
    {
        $outcome = $this->hierarchicalRunner->runQueuedWithCoordination($state);

        if ($outcome instanceof QueueHierarchicalParallelBoundary) {
            $this->durableSwarmManager->enterQueueHierarchicalParallelCoordination(
                $state,
                $outcome,
            );

            return null;
        }

        return $outcome;
    }

    public function resumeAfterParallelJoin(string $runId): void
    {
        $this->application->make(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($runId);
    }

    public static function dispatchResume(string $runId, ?string $connection, ?string $queue): PendingDispatch
    {
        $job = new ResumeQueuedHierarchicalSwarm($runId);

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        return new PendingDispatch($job);
    }
}
