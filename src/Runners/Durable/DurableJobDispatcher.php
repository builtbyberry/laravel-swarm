<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeNativeInputQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * @internal
 */
class DurableJobDispatcher
{
    public function __construct(
        protected ConfigRepository $config,
        protected ?ContextStore $contexts = null,
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
        $this->dispatchQueuedResumeById(
            (string) $run['run_id'],
            $run['queue_connection'] ?? null,
            $run['queue_name'] ?? null,
        );
    }

    public function dispatchQueuedResumeById(string $runId, ?string $connection = null, ?string $queue = null): void
    {
        $connection = $this->config->get('swarm.queue.hierarchical_parallel.resume.connection') ?? $connection;
        $queue = $this->config->get('swarm.queue.hierarchical_parallel.resume.name') ?? $queue;
        $dispatch = new PendingDispatch($this->makeQueuedResumeJob($runId, $connection, $queue));
        unset($dispatch);
    }

    public function makeStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): AdvanceDurableSwarm
    {
        $job = $this->requiresNativeInputReader($runId)
            ? new AdvanceNativeInputDurableSwarm($runId, $stepIndex)
            : new AdvanceDurableSwarm($runId, $stepIndex);

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
        $job = $this->requiresNativeInputReader($runId)
            ? new AdvanceNativeInputDurableBranch($runId, $branchId)
            : new AdvanceDurableBranch($runId, $branchId);

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        return $job;
    }

    public function makeQueuedResumeJob(string $runId, ?string $connection = null, ?string $queue = null): ResumeQueuedHierarchicalSwarm
    {
        $job = $this->requiresNativeInputReader($runId)
            ? new ResumeNativeInputQueuedHierarchicalSwarm($runId)
            : new ResumeQueuedHierarchicalSwarm($runId);

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        return $job;
    }

    protected function requiresNativeInputReader(string $runId): bool
    {
        $context = ($this->contexts ?? Container::getInstance()->make(ContextStore::class))->find($runId);

        return is_string($context['native_input_ref'] ?? null);
    }
}
