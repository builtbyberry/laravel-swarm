<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Events\SwarmChildCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmChildFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmChildStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableChildRun;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Throwable;

/**
 * @phpstan-import-type SwarmTaskInput from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class DurableChildSwarmCoordinator
{
    protected mixed $afterChildIntentHook = null;

    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected Dispatcher $events,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected Application $application,
        protected DurableRunContext $runs,
        protected DurablePayloadCapture $payloads,
        protected DurableJobDispatcher $jobs,
    ) {}

    public function afterChildIntentForTesting(?callable $hook): void
    {
        $this->afterChildIntentHook = $hook;
    }

    /**
     * @param  SwarmTaskInput  $task
     */
    public function dispatchChildSwarm(string $parentRunId, string $childSwarmClass, string|array|RunContext $task, ?string $dedupeKey = null, ?callable $dispatchStep = null): DurableChildRun
    {
        $parent = $this->runs->requireRun($parentRunId);
        $swarm = $this->application->make($childSwarmClass);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve child swarm [{$childSwarmClass}] from the container.");
        }

        $context = RunContext::fromTask($task);
        $context->mergeMetadata(['parent_run_id' => $parentRunId]);
        $waitName = $this->childWaitName($context->runId);

        $this->persistChildIntent($parent, $context, $childSwarmClass, $waitName, $dedupeKey);

        if (is_callable($this->afterChildIntentHook)) {
            ($this->afterChildIntentHook)($parentRunId, $context->runId);
        }

        $this->dispatchChildIntent([
            'parent_run_id' => $parentRunId,
            'child_run_id' => $context->runId,
            'child_swarm_class' => $childSwarmClass,
            'wait_name' => $waitName,
            'context_payload' => $context->toArray(),
            'status' => 'pending',
        ], $dispatchStep);

        $child = $this->durableRuns->childRunForChild($context->runId);

        return new DurableChildRun($parentRunId, $context->runId, $childSwarmClass, (string) ($child['status'] ?? 'pending'));
    }

    /**
     * @param  array<string, mixed>  $parent
     */
    protected function persistChildIntent(array $parent, RunContext $childContext, string $childSwarmClass, string $waitName, ?string $dedupeKey = null): void
    {
        $parentRunId = (string) $parent['run_id'];
        $reason = "Waiting for child swarm [{$childContext->runId}].";
        $metadata = $this->payloads->payload([
            'child_run_id' => $childContext->runId,
            'child_swarm_class' => $childSwarmClass,
        ]);

        $this->connection->transaction(function () use ($parentRunId, $childContext, $childSwarmClass, $waitName, $reason, $metadata, $dedupeKey): void {
            $parentContext = $this->runs->loadContext($parentRunId);
            $dispatched = is_array($parentContext->metadata['durable_dispatched_child_swarms'] ?? null) ? $parentContext->metadata['durable_dispatched_child_swarms'] : [];
            $dispatched[$dedupeKey ?? $childContext->runId] = true;
            $parentContext->mergeMetadata(['durable_dispatched_child_swarms' => $dispatched]);

            $this->durableRuns->createWait($parentRunId, $waitName, $reason, null, $metadata);
            $this->durableRuns->createChildRun($parentRunId, $childContext->runId, $childSwarmClass, $waitName, $this->capture->context($childContext)->toArray());
            $this->contextStore->put($this->capture->activeContext($parentContext), $this->runs->ttlSeconds());
            $this->historyStore->syncDurableState($parentRunId, 'waiting', $this->capture->context($parentContext), $parentContext->metadata, $this->runs->ttlSeconds(), false);
        });

        $this->events->dispatch(new SwarmWaiting(
            runId: $parentRunId,
            swarmClass: $parent['swarm_class'],
            topology: $parent['topology'],
            waitName: $waitName,
            reason: $reason,
            metadata: $metadata,
            executionMode: $this->runs->publicLifecycleExecutionMode($parent),
        ));
    }

    /**
     * @param  array<string, mixed>  $child
     */
    public function dispatchChildIntent(array $child, ?callable $dispatchStep = null): void
    {
        $childRunId = (string) $child['child_run_id'];
        $childSwarmClass = (string) $child['child_swarm_class'];
        $contextPayload = is_array($child['context_payload'] ?? null) ? $child['context_payload'] : [];

        if ($this->durableRuns->find($childRunId) === null) {
            try {
                $swarm = $this->application->make($childSwarmClass);

                if (! $swarm instanceof Swarm) {
                    throw new SwarmException("Unable to resolve child swarm [{$childSwarmClass}] from the container.");
                }

                $response = $this->application->make(SwarmRunner::class)->dispatchDurable($swarm, RunContext::fromPayload($contextPayload));
                unset($response);
            } catch (Throwable $exception) {
                $this->durableRuns->updateChildRun($childRunId, 'failed', null, $this->failurePayload($exception));

                $parent = $this->durableRuns->find((string) $child['parent_run_id']);

                if ($parent !== null) {
                    $this->reconcileTerminalChildrenForParent($parent, $dispatchStep);
                }

                return;
            }
        }

        $this->durableRuns->markChildRunDispatched($childRunId);

        $this->events->dispatch(new SwarmChildStarted(
            parentRunId: (string) $child['parent_run_id'],
            childRunId: $childRunId,
            childSwarmClass: $childSwarmClass,
        ));
    }

    /**
     * @param  array<string, mixed>|null  $failure
     */
    public function markChildTerminalIfNeeded(string $childRunId, string $status, ?string $output, ?array $failure, ?callable $dispatchStep = null): void
    {
        $child = $this->durableRuns->childRunForChild($childRunId);

        if ($child === null || in_array($child['status'], ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $this->durableRuns->updateChildRun($childRunId, $status, $output, $failure);
        $parent = $this->durableRuns->find((string) $child['parent_run_id']);

        if ($parent !== null) {
            $this->reconcileTerminalChildrenForParent($parent, $dispatchStep);
        }
    }

    /**
     * @param  array<string, mixed>  $parent
     */
    public function reconcileTerminalChildrenForParent(array $parent, ?callable $dispatchStep = null): void
    {
        $dispatchStep ??= fn (string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null) => $this->jobs->dispatchStep($runId, $stepIndex, $connection, $queue);

        foreach ($this->durableRuns->childRuns($parent['run_id']) as $child) {
            if (! in_array($child['status'], ['completed', 'failed', 'cancelled'], true)) {
                continue;
            }

            $waitName = (string) ($child['wait_name'] ?? $this->childWaitName((string) $child['child_run_id']));

            if (! $this->waitIsOpen($parent['run_id'], $waitName)) {
                continue;
            }

            $released = $this->connection->transaction(function () use ($parent, $child, $waitName): bool {
                if (! $this->durableRuns->releaseWaitWithOutcome($parent['run_id'], $waitName, 'child_'.$child['status'], [
                    'status' => $child['status'],
                    'child_run_id' => $child['child_run_id'],
                    'timed_out' => false,
                ])) {
                    return false;
                }

                $context = $this->runs->loadContext($parent['run_id']);
                $children = is_array($context->metadata['durable_child_runs'] ?? null) ? $context->metadata['durable_child_runs'] : [];
                $children[$child['child_run_id']] = [
                    'status' => $child['status'],
                    'child_swarm_class' => $child['child_swarm_class'],
                ];
                $context->mergeMetadata(['durable_child_runs' => $children]);
                $this->contextStore->put($this->capture->activeContext($context), $this->runs->ttlSeconds());
                $this->historyStore->syncDurableState($parent['run_id'], 'pending', $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false);

                return $this->durableRuns->markChildTerminalEventDispatched((string) $child['child_run_id']);
            });

            if (! $released) {
                continue;
            }

            if ($child['status'] === 'completed') {
                $this->events->dispatch(new SwarmChildCompleted($parent['run_id'], (string) $child['child_run_id'], (string) $child['child_swarm_class']));
            } else {
                $this->events->dispatch(new SwarmChildFailed($parent['run_id'], (string) $child['child_run_id'], (string) $child['child_swarm_class'], is_array($child['failure'] ?? null) ? $child['failure'] : null));
            }

            $updated = $this->runs->requireRun($parent['run_id']);
            $dispatch = $dispatchStep($parent['run_id'], (int) $updated['next_step_index'], $updated['queue_connection'], $updated['queue_name']);
            unset($dispatch);
        }
    }

    public function cancelActiveChildren(string $parentRunId, callable $cancelChild): void
    {
        foreach ($this->durableRuns->childRuns($parentRunId) as $child) {
            if (in_array($child['status'], ['completed', 'failed', 'cancelled'], true)) {
                continue;
            }

            try {
                $cancelChild((string) $child['child_run_id']);
            } catch (SwarmException) {
                $this->durableRuns->updateChildRun((string) $child['child_run_id'], 'cancelled');
            }
        }
    }

    public function childWaitName(string $childRunId): string
    {
        return 'child:'.$childRunId;
    }

    protected function waitIsOpen(string $runId, string $name): bool
    {
        foreach ($this->durableRuns->waits($runId) as $wait) {
            if (($wait['name'] ?? null) === $name && ($wait['status'] ?? null) === 'waiting') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{message: string, class: class-string<Throwable>}
     */
    protected function failurePayload(Throwable $exception): array
    {
        return [
            'message' => $this->capture->failureMessage($exception),
            'class' => $exception::class,
        ];
    }
}
