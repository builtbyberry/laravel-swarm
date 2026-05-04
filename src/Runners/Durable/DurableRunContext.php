<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;

class DurableRunContext
{
    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected ContextStore $contextStore,
        protected DatabaseRunHistoryStore $historyStore,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function requireRun(string $runId): array
    {
        $run = $this->durableRuns->find($runId);

        if ($run === null) {
            throw new SwarmException("Durable run [{$runId}] was not found.");
        }

        return $run;
    }

    public function loadContext(string $runId): RunContext
    {
        $payload = $this->contextStore->find($runId);

        if ($payload === null) {
            throw new SwarmException("Durable run [{$runId}] is missing its persisted context.");
        }

        return RunContext::fromPayload($payload);
    }

    public function ttlSeconds(): int
    {
        return (int) $this->config->get('swarm.context.ttl', 3600);
    }

    /**
     * Public lifecycle events keep `execution_mode: queue` for coordinated queued hierarchical runs.
     *
     * @param  array<string, mixed>  $run
     */
    public function publicLifecycleExecutionMode(array $run): string
    {
        if ($this->isQueueHierarchicalParallel($run)) {
            return ExecutionMode::Queue->value;
        }

        return ExecutionMode::Durable->value;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function isQueueHierarchicalParallel(array $run): bool
    {
        return ($run['coordination_profile'] ?? CoordinationProfile::StepDurable->value) === CoordinationProfile::QueueHierarchicalParallel->value;
    }

    public function durationMillisecondsFor(string $runId): int
    {
        $history = $this->historyStore->find($runId);
        $startedAt = isset($history['started_at']) ? Carbon::parse($history['started_at'], 'UTC') : null;

        if ($startedAt === null) {
            return 1;
        }

        return max((int) $startedAt->diffInMilliseconds(Carbon::now('UTC')), 1);
    }
}
