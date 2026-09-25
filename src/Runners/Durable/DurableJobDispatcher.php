<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeAgentSettingsDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeAgentSettingsDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeNativeAgentSettingsQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeNativeInputQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManifest;
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
        protected ?NativeInputStore $nativeInputs = null,
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
        $job = match ($this->nativeInputVersion($runId)) {
            NativeInputManifest::SETTINGS_VERSION => new AdvanceNativeAgentSettingsDurableSwarm($runId, $stepIndex),
            NativeInputManifest::VERSION => new AdvanceNativeInputDurableSwarm($runId, $stepIndex),
            default => new AdvanceDurableSwarm($runId, $stepIndex),
        };

        if ($job instanceof AdvanceNativeInputDurableSwarm || $job instanceof AdvanceNativeAgentSettingsDurableSwarm) {
            $job->afterCommit();
        }

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
        $job = match ($this->nativeInputVersion($runId)) {
            NativeInputManifest::SETTINGS_VERSION => new AdvanceNativeAgentSettingsDurableBranch($runId, $branchId),
            NativeInputManifest::VERSION => new AdvanceNativeInputDurableBranch($runId, $branchId),
            default => new AdvanceDurableBranch($runId, $branchId),
        };

        if ($job instanceof AdvanceNativeInputDurableBranch || $job instanceof AdvanceNativeAgentSettingsDurableBranch) {
            $job->afterCommit();
        }

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
        $job = match ($this->nativeInputVersion($runId)) {
            NativeInputManifest::SETTINGS_VERSION => new ResumeNativeAgentSettingsQueuedHierarchicalSwarm($runId),
            NativeInputManifest::VERSION => new ResumeNativeInputQueuedHierarchicalSwarm($runId),
            default => new ResumeQueuedHierarchicalSwarm($runId),
        };

        if ($job instanceof ResumeNativeInputQueuedHierarchicalSwarm || $job instanceof ResumeNativeAgentSettingsQueuedHierarchicalSwarm) {
            $job->afterCommit();
        }

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        return $job;
    }

    protected function nativeInputVersion(string $runId): ?int
    {
        $context = ($this->contexts ?? Container::getInstance()->make(ContextStore::class))->find($runId);

        $reference = $context['native_input_ref'] ?? null;
        if (! is_string($reference)) {
            return null;
        }

        $row = ($this->nativeInputs ?? Container::getInstance()->make(NativeInputStore::class))->find($reference);

        if (! is_array($row)) {
            return NativeInputManifest::VERSION;
        }

        return (int) ($row['format_version'] ?? ($row['payload']['version'] ?? NativeInputManifest::VERSION));
    }
}
