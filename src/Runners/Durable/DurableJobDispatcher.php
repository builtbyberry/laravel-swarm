<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Bus\PendingDispatch;

class DurableJobDispatcher
{
    public function __construct(
        protected ConfigRepository $config,
    ) {}

    public function dispatchStep(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
    {
        return new PendingDispatch($this->makeStepJob($runId, $stepIndex, $connection, $queue));
    }

    public function dispatchBranch(string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch
    {
        return new PendingDispatch($this->makeBranchJob($runId, $branchId, $connection, $queue));
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function dispatchQueuedHierarchicalResume(array $run): void
    {
        $connection = $this->config->get('swarm.queue.hierarchical_parallel.resume.connection')
            ?? ($run['queue_connection'] ?? null);
        $queue = $this->config->get('swarm.queue.hierarchical_parallel.resume.name')
            ?? ($run['queue_name'] ?? null);
        $dispatch = QueuedHierarchicalCoordinator::dispatchResume($run['run_id'], $connection, $queue);
        unset($dispatch);
    }

    public function makeStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): AdvanceDurableSwarm
    {
        $job = new AdvanceDurableSwarm($runId, $stepIndex);

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        return $job;
    }

    public function makeBranchJob(string $runId, string $branchId, ?string $connection = null, ?string $queue = null): AdvanceDurableBranch
    {
        $job = new AdvanceDurableBranch($runId, $branchId);

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        return $job;
    }
}
