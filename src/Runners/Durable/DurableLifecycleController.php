<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;

class DurableLifecycleController
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected Dispatcher $events,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected DurableRunContext $runs,
        protected DurablePayloadCapture $payloads,
        protected DurableChildSwarmCoordinator $children,
    ) {}

    public function pause(string $runId): bool
    {
        $run = $this->runs->requireRun($runId);
        $context = null;
        $updated = null;

        $paused = $this->connection->transaction(function () use ($runId, &$context, &$updated): bool {
            if (! $this->durableRuns->pause($runId)) {
                return false;
            }

            $updated = $this->runs->requireRun($runId);

            if ($updated['status'] === 'paused') {
                $context = $this->runs->loadContext($runId);
                $this->historyStore->syncDurableState($runId, 'paused', $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false);
            }

            return true;
        });

        if (! $paused) {
            throw new SwarmException("Durable run [{$runId}] cannot be paused from status [{$run['status']}].");
        }

        if (is_array($updated) && $updated['status'] === 'paused' && $context !== null) {
            $this->events->dispatch(new SwarmPaused(
                runId: $runId,
                swarmClass: $updated['swarm_class'],
                topology: $updated['topology'],
                metadata: $this->payloads->eventMetadata($context),
                executionMode: $this->runs->publicLifecycleExecutionMode($updated),
            ));
        }

        return true;
    }

    /**
     * @return array{waiting: array<string, mixed>|null, dispatchStep: array{runId: string, stepIndex: int, connection: ?string, queue: ?string}|null}
     */
    public function resume(string $runId): array
    {
        $run = $this->runs->requireRun($runId);

        if ($run['status'] !== 'paused') {
            throw new SwarmException("Durable run [{$runId}] cannot be resumed from status [{$run['status']}].");
        }

        $context = null;
        $updated = null;

        $resumed = $this->connection->transaction(function () use ($runId, &$context, &$updated): bool {
            if (! $this->durableRuns->resume($runId)) {
                return false;
            }

            $updated = $this->runs->requireRun($runId);
            $context = $this->runs->loadContext($runId);
            $this->historyStore->syncDurableState($runId, $updated['status'], $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false);

            return true;
        });

        if (! $resumed || $context === null) {
            throw new SwarmException("Durable run [{$runId}] could not be resumed.");
        }

        $this->events->dispatch(new SwarmResumed(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            metadata: $this->payloads->eventMetadata($context),
            executionMode: $this->runs->publicLifecycleExecutionMode($updated),
        ));

        if (is_array($updated) && $updated['status'] === 'waiting') {
            return ['waiting' => $updated, 'dispatchStep' => null];
        }

        $dispatchStep = $this->runs->isQueueHierarchicalParallel($updated) ? null : [
            'runId' => $runId,
            'stepIndex' => (int) ($updated['next_step_index'] ?? $run['next_step_index']),
            'connection' => $run['queue_connection'],
            'queue' => $run['queue_name'],
        ];

        return ['waiting' => null, 'dispatchStep' => $dispatchStep];
    }

    public function cancel(string $runId, callable $cancelChild): bool
    {
        $run = $this->runs->requireRun($runId);

        if (in_array($run['status'], ['completed', 'failed', 'cancelled'], true)) {
            throw new SwarmException("Durable run [{$runId}] cannot be cancelled from status [{$run['status']}].");
        }

        $context = null;
        $updated = null;

        $cancelled = $this->connection->transaction(function () use ($runId, &$context, &$updated): bool {
            if (! $this->durableRuns->cancel($runId)) {
                return false;
            }

            $updated = $this->runs->requireRun($runId);

            if ($updated['status'] === 'cancelled') {
                $context = $this->runs->loadContext($runId);
                $this->contextStore->put($this->capture->terminalContext($context), $this->runs->ttlSeconds());
                $this->historyStore->syncDurableState($runId, 'cancelled', $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), true);
            }

            return true;
        });

        if (! $cancelled) {
            throw new SwarmException("Durable run [{$runId}] could not be cancelled.");
        }

        if (is_array($updated) && $updated['status'] === 'cancelled' && $context !== null) {
            $this->children->cancelActiveChildren($runId, $cancelChild);

            $this->events->dispatch(new SwarmCancelled(
                runId: $runId,
                swarmClass: $updated['swarm_class'],
                topology: $updated['topology'],
                metadata: $this->payloads->eventMetadata($context),
                executionMode: $this->runs->publicLifecycleExecutionMode($updated),
            ));
        }

        return true;
    }

    public function updateQueueRouting(string $runId, ?string $connection, ?string $queue): void
    {
        $this->durableRuns->updateQueueRouting($runId, $connection, $queue);
    }
}
