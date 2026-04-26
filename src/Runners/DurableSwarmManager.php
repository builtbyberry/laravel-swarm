<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Carbon;
use Throwable;

class DurableSwarmManager
{
    protected mixed $beforeStepCheckpointHook = null;

    protected mixed $afterStepCheckpointHook = null;

    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected Dispatcher $events,
        protected SequentialRunner $sequential,
        protected HierarchicalRunner $hierarchical,
        protected DurableRunRecorder $recorder,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): void
    {
        $this->durableRuns->create($payload);
    }

    public function start(Swarm $swarm, RunContext $context, Topology $topology, int $timeoutSeconds, int $totalSteps): DurableSwarmStart
    {
        $this->limits->checkInput($context->input);

        return $this->connection->transaction(function () use ($swarm, $context, $topology, $timeoutSeconds, $totalSteps): DurableSwarmStart {
            $contextTtl = $this->ttlSeconds();
            $connection = $this->config->get('swarm.durable.queue.connection');
            $queue = $this->config->get('swarm.durable.queue.name');

            $context->mergeMetadata([
                'swarm_class' => $swarm::class,
                'topology' => $topology->value,
                'execution_mode' => 'durable',
                'completed_steps' => 0,
                'total_steps' => $totalSteps,
            ]);

            $this->historyStore->start($context->runId, $swarm::class, $topology->value, $this->capture->context($context), $context->metadata, $contextTtl);
            $this->contextStore->put($this->capture->activeContext($context), $contextTtl);
            $this->historyStore->syncDurableState($context->runId, 'pending', $this->capture->context($context), $context->metadata, $contextTtl, false);

            $this->durableRuns->create([
                'run_id' => $context->runId,
                'swarm_class' => $swarm::class,
                'topology' => $topology->value,
                'status' => 'pending',
                'next_step_index' => 0,
                'current_step_index' => null,
                'total_steps' => $totalSteps,
                'timeout_at' => now('UTC')->addSeconds($timeoutSeconds),
                'step_timeout_seconds' => $this->resolveStepTimeoutSeconds(),
                'execution_token' => null,
                'leased_until' => null,
                'pause_requested_at' => null,
                'cancel_requested_at' => null,
                'queue_connection' => $connection,
                'queue_name' => $queue,
                'finished_at' => null,
            ]);

            return new DurableSwarmStart($context->runId, $this->makeStepJob($context->runId, 0, $connection, $queue));
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array
    {
        return $this->durableRuns->find($runId);
    }

    public function pause(string $runId): bool
    {
        $run = $this->requireRun($runId);
        $context = null;
        $updated = null;

        $paused = $this->connection->transaction(function () use ($runId, &$context, &$updated): bool {
            if (! $this->durableRuns->pause($runId)) {
                return false;
            }

            $updated = $this->requireRun($runId);

            if ($updated['status'] === 'paused') {
                $context = $this->loadContext($runId);
                $this->historyStore->syncDurableState($runId, 'paused', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false);
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
                metadata: $context->metadata,
                executionMode: 'durable',
            ));
        }

        return true;
    }

    public function resume(string $runId): bool
    {
        $run = $this->requireRun($runId);

        if ($run['status'] !== 'paused') {
            throw new SwarmException("Durable run [{$runId}] cannot be resumed from status [{$run['status']}].");
        }

        $context = null;

        $resumed = $this->connection->transaction(function () use ($runId, &$context): bool {
            if (! $this->durableRuns->resume($runId)) {
                return false;
            }

            $context = $this->loadContext($runId);
            $this->historyStore->syncDurableState($runId, 'pending', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false);

            return true;
        });

        if (! $resumed || $context === null) {
            throw new SwarmException("Durable run [{$runId}] could not be resumed.");
        }

        $this->events->dispatch(new SwarmResumed(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            metadata: $context->metadata,
            executionMode: 'durable',
        ));

        $this->dispatchStepJob($runId, (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);

        return true;
    }

    public function cancel(string $runId): bool
    {
        $run = $this->requireRun($runId);

        if (in_array($run['status'], ['completed', 'failed', 'cancelled'], true)) {
            throw new SwarmException("Durable run [{$runId}] cannot be cancelled from status [{$run['status']}].");
        }

        $context = null;
        $updated = null;

        $cancelled = $this->connection->transaction(function () use ($runId, &$context, &$updated): bool {
            if (! $this->durableRuns->cancel($runId)) {
                return false;
            }

            $updated = $this->requireRun($runId);

            if ($updated['status'] === 'cancelled') {
                $context = $this->loadContext($runId);
                $this->contextStore->put($this->capture->terminalContext($context), $this->ttlSeconds());
                $this->historyStore->syncDurableState($runId, 'cancelled', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), true);
            }

            return true;
        });

        if (! $cancelled) {
            throw new SwarmException("Durable run [{$runId}] could not be cancelled.");
        }

        if (is_array($updated) && $updated['status'] === 'cancelled' && $context !== null) {
            $this->events->dispatch(new SwarmCancelled(
                runId: $runId,
                swarmClass: $updated['swarm_class'],
                topology: $updated['topology'],
                metadata: $context->metadata,
                executionMode: 'durable',
            ));
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function recover(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        $runs = $this->durableRuns->recoverable(
            runId: $runId,
            swarmClass: $swarmClass,
            limit: $limit,
            graceSeconds: (int) $this->config->get('swarm.durable.recovery.grace_seconds', 300),
        );

        foreach ($runs as $run) {
            $this->dispatchStepJob($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
        }

        return array_map(static fn (array $run): string => $run['run_id'], $runs);
    }

    public function updateQueueRouting(string $runId, ?string $connection, ?string $queue): void
    {
        $this->durableRuns->updateQueueRouting($runId, $connection, $queue);
    }

    /**
     * @internal Testing hook for crash/recovery coverage. Not part of the public package API.
     */
    public function afterStepCheckpointForTesting(?callable $hook): void
    {
        $this->afterStepCheckpointHook = $hook;
    }

    /**
     * @internal Testing hook for crash/recovery coverage. Not part of the public package API.
     */
    public function beforeStepCheckpointForTesting(?callable $hook): void
    {
        $this->beforeStepCheckpointHook = $hook;
    }

    public function advance(string $runId, int $expectedStepIndex): void
    {
        $run = $this->requireRun($runId);
        $stepLeaseSeconds = $this->validateStepTimeoutSeconds((int) $run['step_timeout_seconds']);
        $token = $this->durableRuns->acquireLease($runId, $expectedStepIndex, $stepLeaseSeconds);

        if ($token === null) {
            return;
        }

        $run = $this->requireRun($runId);
        $context = $this->loadContext($runId);
        $stepLeaseSeconds = $this->validateStepTimeoutSeconds((int) $run['step_timeout_seconds']);

        if ($this->hasTimedOut($run)) {
            $exception = new SwarmException("Durable swarm run [{$runId}] exceeded its configured timeout.");
            try {
                $this->recorder->fail($runId, $token, $exception, $context, $stepLeaseSeconds);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->events->dispatch(new SwarmFailed(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                exception: $this->capture->failureException($exception),
                durationMs: $this->durationMillisecondsFor($runId),
                metadata: $context->metadata,
                executionMode: 'durable',
                exceptionClass: $exception::class,
            ));

            return;
        }

        if (($run['cancel_requested_at'] ?? null) !== null) {
            try {
                $this->recorder->cancel($runId, $token, $context);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->events->dispatch(new SwarmCancelled(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                metadata: $context->metadata,
                executionMode: 'durable',
            ));

            return;
        }

        $swarm = app()->make($run['swarm_class']);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve durable swarm [{$run['swarm_class']}] from the container.");
        }

        $this->connection->transaction(function () use ($runId, $token, $expectedStepIndex, $context, $stepLeaseSeconds): void {
            $this->durableRuns->markRunning($runId, $token, $expectedStepIndex);
            $this->historyStore->syncDurableState($runId, 'running', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false, $token, $stepLeaseSeconds);
        });

        if ($expectedStepIndex === 0 && $run['current_step_index'] === null) {
            $this->events->dispatch(new SwarmStarted(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                input: $this->capture->input($context->input),
                metadata: $context->metadata,
                executionMode: 'durable',
            ));
        }

        $timeoutSeconds = max((int) ceil((Carbon::parse($run['timeout_at'], 'UTC')->diffInSeconds(now('UTC'), false)) * -1), 1);
        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: $run['topology'],
            executionMode: 'durable',
            deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: (int) $run['total_steps'],
            ttlSeconds: $this->ttlSeconds(),
            leaseSeconds: $stepLeaseSeconds,
            executionToken: $token,
            verifyOwnership: fn (): null => $this->durableRuns->assertOwned($runId, $token),
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
        );

        $startedAt = MonotonicTime::now();
        $hierarchicalResult = null;

        try {
            if ($run['topology'] === Topology::Hierarchical->value) {
                $hierarchicalResult = $this->hierarchical->runDurableStep($state, $expectedStepIndex, $run);
                $step = $hierarchicalResult->step;
            } else {
                $step = $this->sequential->runSingleStep($state, $expectedStepIndex);
            }
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            return;
        } catch (Throwable $exception) {
            try {
                $this->recorder->fail($runId, $token, $exception, $context, $stepLeaseSeconds);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->events->dispatch(new SwarmFailed(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                exception: $this->capture->failureException($exception),
                durationMs: $this->durationMillisecondsFor($runId),
                metadata: $context->metadata,
                executionMode: 'durable',
                exceptionClass: $exception::class,
            ));

            throw $exception;
        }

        if (($run = $this->requireRun($runId)) && ($run['cancel_requested_at'] ?? null) !== null) {
            try {
                $this->recorder->cancel($runId, $token, $context, $step);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->events->dispatch(new SwarmCancelled(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                metadata: $context->metadata,
                executionMode: 'durable',
            ));

            return;
        }

        if (($run['pause_requested_at'] ?? null) !== null) {
            try {
                $this->recorder->pauseAtBoundary($runId, $token, $context);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->events->dispatch(new SwarmPaused(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                metadata: $context->metadata,
                executionMode: 'durable',
            ));

            return;
        }

        $nextStepIndex = $expectedStepIndex + 1;
        $isComplete = $run['topology'] === Topology::Hierarchical->value
            ? $hierarchicalResult?->complete === true
            : $nextStepIndex >= (int) $run['total_steps'];

        if ($isComplete) {
            $response = new SwarmResponse(
                output: (string) ($context->data['last_output'] ?? $context->input),
                steps: $step !== null ? [$step] : [],
                usage: is_array($context->metadata['usage'] ?? null) ? $context->metadata['usage'] : [],
                context: $context,
                artifacts: $context->artifacts,
                metadata: array_merge($context->metadata, [
                    'run_id' => $runId,
                    'topology' => $run['topology'],
                ]),
            );

            try {
                $capturedResponse = $this->limits->response($this->capture->response($response));
                $this->recorder->complete($runId, $token, $context, $capturedResponse, $stepLeaseSeconds, $step);
            } catch (LostDurableLeaseException|LostSwarmLeaseException) {
                return;
            }
            $this->events->dispatch(new SwarmCompleted(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                output: $capturedResponse->output,
                durationMs: $this->durationMillisecondsFor($runId),
                metadata: $capturedResponse->metadata,
                artifacts: $capturedResponse->artifacts,
                executionMode: 'durable',
            ));

            return;
        }

        try {
            $context->mergeMetadata([
                'completed_steps' => $nextStepIndex,
                'total_steps' => $context->metadata['total_steps'] ?? (int) $run['total_steps'],
            ]);

            if (is_callable($this->beforeStepCheckpointHook)) {
                ($this->beforeStepCheckpointHook)($runId, $nextStepIndex);
            }

            if ($hierarchicalResult !== null) {
                $this->recorder->checkpointHierarchical($runId, $token, $nextStepIndex, $context, $stepLeaseSeconds, $hierarchicalResult, $step);
            } else {
                $this->recorder->checkpointSequential($runId, $token, $nextStepIndex, $context, $stepLeaseSeconds);
            }
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            return;
        }

        if (is_callable($this->afterStepCheckpointHook)) {
            ($this->afterStepCheckpointHook)($runId, $nextStepIndex);
        }

        $this->dispatchStepJob($runId, $nextStepIndex, $run['queue_connection'], $run['queue_name']);
    }

    public function dispatchStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch
    {
        return new PendingDispatch($this->makeStepJob($runId, $stepIndex, $connection, $queue));
    }

    protected function requireRun(string $runId): array
    {
        $run = $this->durableRuns->find($runId);

        if ($run === null) {
            throw new SwarmException("Durable run [{$runId}] was not found.");
        }

        return $run;
    }

    protected function loadContext(string $runId): RunContext
    {
        $payload = $this->contextStore->find($runId);

        if ($payload === null) {
            throw new SwarmException("Durable run [{$runId}] is missing its persisted context.");
        }

        return RunContext::fromPayload($payload);
    }

    protected function hasTimedOut(array $run): bool
    {
        return Carbon::parse($run['timeout_at'], 'UTC')->isPast();
    }

    protected function ttlSeconds(): int
    {
        return (int) $this->config->get('swarm.context.ttl', 3600);
    }

    protected function resolveStepTimeoutSeconds(): int
    {
        return $this->validateStepTimeoutSeconds((int) $this->config->get('swarm.durable.step_timeout', 300));
    }

    protected function validateStepTimeoutSeconds(int $seconds): int
    {
        if ($seconds <= 0) {
            throw new SwarmException('Durable swarm step timeout must be a positive integer.');
        }

        return $seconds;
    }

    protected function durationMillisecondsFor(string $runId): int
    {
        $history = $this->historyStore->find($runId);
        $startedAt = isset($history['started_at']) ? Carbon::parse($history['started_at'], 'UTC') : null;

        if ($startedAt === null) {
            return 1;
        }

        return max((int) $startedAt->diffInMilliseconds(Carbon::now('UTC')), 1);
    }

    protected function makeStepJob(string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): AdvanceDurableSwarm
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
}
