<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;

/**
 * Shared context helper for the durable manager graph.
 *
 * This class is intentionally stateless — it delegates entirely to immutable
 * constructor dependencies (ConfigRepository, DurableRunStore, ContextStore,
 * DatabaseRunHistoryStore). It is safe to construct multiple times with the
 * same underlying services; all instances will behave identically.
 *
 * Do NOT add mutable, run-scoped, or request-scoped fields here. Any such
 * addition would break the single-construction-path contract enforced by
 * DurableManagerCollaboratorFactory, where one DurableRunContext instance
 * is shared across every manager collaborator.
 *
 * @internal
 */
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
        return $this->contextFromPayload($runId, $this->contextStore->find($runId));
    }

    /**
     * The context a caller writes back or publishes from: read with a locking read
     * (DatabaseContextStore::findForUpdate()), so it is the latest committed row and not
     * an application transaction's older MySQL snapshot. Call it inside the caller's
     * transaction, once the run row is locked.
     *
     * Durable dispatch requires a DatabaseContextStore
     * (DispatchValidator::ensureDatabaseDurableInfrastructure()), so every durable run
     * reads this way. Any other ContextStore is read with find(), with no lock: that is
     * safe only while durable dispatch keeps that requirement. A change that lets
     * another ContextStore run durable swarms must give it a locking read here, or #636
     * (a closer erasing a fan-out's context on MySQL) returns for it.
     */
    public function loadContextForUpdate(string $runId): RunContext
    {
        return $this->contextFromPayload($runId, $this->contextStore instanceof DatabaseContextStore
            ? $this->contextStore->findForUpdate($runId)
            : $this->contextStore->find($runId));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function contextFromPayload(string $runId, ?array $payload): RunContext
    {
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
     * @param  array<string, mixed>  $run
     */
    public function hasTimedOut(array $run): bool
    {
        return Carbon::parse($run['timeout_at'], 'UTC')->isPast();
    }

    public function validateStepTimeoutSeconds(int $seconds): int
    {
        if ($seconds <= 0) {
            throw new SwarmException('Durable swarm step timeout must be a positive integer.');
        }

        return $seconds;
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

    /**
     * Resolve swarm_class from the durable run row.
     *
     * Used by DurableRunRecorder to source non-null swarm_class on every
     * durable.* audit emit rather than relying on the optional
     * RunContext::metadata['swarm_class'] mirror.
     */
    public function swarmClassFor(string $runId): string
    {
        return (string) $this->requireRun($runId)['swarm_class'];
    }

    /**
     * Resolve topology from the durable run row.
     *
     * Used by DurableRunRecorder to source non-null topology on every
     * durable.* audit emit rather than relying on the optional
     * RunContext::metadata['topology'] mirror.
     */
    public function topologyFor(string $runId): string
    {
        return (string) $this->requireRun($runId)['topology'];
    }
}
