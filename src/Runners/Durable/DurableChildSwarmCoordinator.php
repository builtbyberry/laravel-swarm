<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
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
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @phpstan-import-type SwarmTaskInput from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 *
 * @internal
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
        protected DurableOutbox $outbox,
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
        protected SwarmAuditDispatcher $audit,
    ) {}

    public function afterChildIntentForTesting(?callable $hook): void
    {
        $this->afterChildIntentHook = $hook;
    }

    /**
     * @param  SwarmTaskInput  $task
     */
    public function dispatchChildSwarm(string $parentRunId, string $childSwarmClass, string|array|RunContext $task, ?string $dedupeKey = null): DurableChildRun
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
        ]);

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
    public function dispatchChildIntent(array $child): void
    {
        $childRunId = (string) $child['child_run_id'];
        $childSwarmClass = (string) $child['child_swarm_class'];
        // Use the caller-provided payload. First dispatch passes the live in-memory
        // RunContext (the real, unsealed input); the recovery path passes a child that
        // DurableRecoveryCoordinator already re-read strictly (decrypt-or-throw) so a
        // wrong/rotated APP_KEY is isolated to that child before we ever get here. Do NOT
        // re-read the stored context_payload for the operational input — that column is the
        // capture/evidence view and is `[redacted]` under swarm.capture.inputs=false.
        $contextPayload = is_array($child['context_payload'] ?? null) ? $child['context_payload'] : [];

        // The CLAIM is authoritative, not the error classifier. More than one worker can
        // arrive here for the same child: `swarm:recover` sweeps children the parent may be
        // inline-dispatching this instant, and the withoutOverlapping() the installer
        // scaffolds never serialises a sweep against that inline dispatch — it only
        // serialises sweep against sweep, and only when the schedule mutex's cache store is
        // shared and lock-capable. A manual `php artisan swarm:recover` is outside it
        // entirely. Winning this conditional update is what grants the right to dispatch —
        // exactly one worker wins however many arrive — so a duplicate start() is
        // unreachable rather than something we must recognise from a driver-specific
        // SQLSTATE after the fact. Everything below runs for the winner.
        $dispatchedAt = CarbonImmutable::now('UTC');

        if (! $this->durableRuns->markChildRunDispatched($childRunId, $dispatchedAt)) {
            return;
        }

        // A run row already exists for a child we just claimed: an earlier attempt
        // committed start() and then had its claim handed back without the run being
        // reaped. Do not dispatch it a second time; its execution is recoverable()'s
        // problem. SwarmChildStarted deliberately does NOT fire here —
        // start() commits the row inside a transaction but enqueues the job later, at
        // PendingDispatch destruct, so a row alone is not evidence anything is executing.
        // The claim is deliberately KEPT here rather than handed back: the run exists, so
        // the child genuinely is dispatched, and the claim is what stops the sweep
        // re-selecting it on every pass.
        if ($this->durableRuns->find($childRunId) !== null) {
            return;
        }

        try {
            $swarm = $this->application->make($childSwarmClass);

            if (! $swarm instanceof Swarm) {
                throw new SwarmException("Unable to resolve child swarm [{$childSwarmClass}] from the container.");
            }

            $response = $this->application->make(SwarmRunner::class)->dispatchDurable($swarm, RunContext::fromPayload($contextPayload));
            unset($response);
        } catch (Throwable $exception) {
            $this->failDispatch($childRunId, $dispatchedAt, $child, $exception);

            return;
        }

        // Gated on the claim, so delivery is attempted at most once per child.
        // The child is already durable at this point; an application listener must
        // not turn its own notification failure into a parent retry or duplicate run.
        try {
            $this->events->dispatch(new SwarmChildStarted(
                parentRunId: (string) $child['parent_run_id'],
                childRunId: $childRunId,
                childSwarmClass: $childSwarmClass,
            ));
        } catch (Throwable $exception) {
            $failure = $this->capture->failureArray($exception);

            $this->logger->warning('laravel-swarm: a SwarmChildStarted listener failed after the child was dispatched; the event will not be retried.', [
                'parent_run_id' => $child['parent_run_id'] ?? null,
                'child_run_id' => $childRunId,
                'child_swarm_class' => $childSwarmClass,
                'exception_class' => $failure['class'],
                'exception_message' => $failure['message'] ?? null,
            ]);

            try {
                $this->audit->emit('child.started_delivery_failed', [
                    'parent_run_id' => $child['parent_run_id'] ?? null,
                    'child_run_id' => $childRunId,
                    'child_swarm_class' => $childSwarmClass,
                    'exception_class' => $failure['class'],
                    'exception_message' => $failure['message'] ?? null,
                ]);
            } catch (Throwable $auditException) {
                // The child is already durable. Evidence-delivery failure cannot
                // safely turn notification failure into a replayable parent error.
                $auditFailure = $this->capture->failureArray($auditException);
                $this->logger->error('laravel-swarm: child-start listener failure evidence could not be delivered; the dispatched child remains authoritative.', [
                    'parent_run_id' => $child['parent_run_id'] ?? null,
                    'child_run_id' => $childRunId,
                    'exception_class' => $auditFailure['class'],
                    'exception_message' => $auditFailure['message'] ?? null,
                ]);
            }
        }
    }

    /**
     * Resolve the failure of a dispatch this worker holds the claim for.
     *
     * @param  array<string, mixed>  $child
     */
    protected function failDispatch(string $childRunId, CarbonImmutable $dispatchedAt, array $child, Throwable $exception): void
    {
        // start() commits the run row inside a transaction and enqueues the job later, at
        // PendingDispatch destruct — so an exception raised on the way out lands here with
        // the row already persisted. Marking that child failed would bury a run that is
        // genuinely recoverable, which is the same reasoning the run-row gate above
        // applies. Hand the claim back and let recoverable() own it.
        if ($this->durableRuns->find($childRunId) !== null) {
            // Keep the claim: the run exists, so the child is dispatched and recovery owns
            // driving it. Handing the claim back here would put it straight back into the
            // sweep, which would re-claim, re-see the run, and release again on every pass.
            $this->logger->warning('laravel-swarm: durable child dispatch failed after its run was created; leaving it to recovery rather than failing it.', [
                'child_run_id' => $childRunId,
                'parent_run_id' => $child['parent_run_id'] ?? null,
                'exception' => $exception::class,
            ]);

            return;
        }

        // A dispatch failure stays terminal: the retry path is gated on separating the
        // child's operational input from the capture/evidence view, because a swept retry
        // re-reads a `[redacted]` input under swarm.capture.inputs=false and would run the
        // child on the literal sentinel. Until that lands the child fails loudly.
        //
        // The write is conditional: the child may have completed under another worker while
        // this dispatch was failing, and a no-op means that worker owns its terminal state
        // and is already reconciling the parent.
        $failed = $this->durableRuns->updateChildRun($childRunId, 'failed', null, $this->failurePayload($exception), ['pending']);

        if (! $failed) {
            $this->logger->info('laravel-swarm: durable child dispatch failed but the child had already reached a terminal status; leaving reconciliation to its owner.', [
                'child_run_id' => $childRunId,
                'parent_run_id' => $child['parent_run_id'] ?? null,
                'exception' => $exception::class,
            ]);
        }

        $this->releaseClaim($childRunId, $dispatchedAt, $child);

        if (! $failed) {
            return;
        }

        $parent = $this->durableRuns->find((string) $child['parent_run_id']);

        if ($parent !== null) {
            $this->reconcileTerminalChildrenForParent($parent);
        }
    }

    /**
     * Hand this worker's dispatch claim back.
     *
     * A claim held by a worker that dispatched nothing keeps the child out of every
     * recovery sweep. Claims are not reclaimed by age, so a failed release needs an
     * actionable operator signal rather than a false promise of automatic recovery.
     *
     * @param  array<string, mixed>  $child
     */
    protected function releaseClaim(string $childRunId, CarbonImmutable $dispatchedAt, array $child): void
    {
        if ($this->durableRuns->releaseChildRunDispatch($childRunId, $dispatchedAt)) {
            return;
        }

        $this->logger->warning('laravel-swarm: could not hand back a durable child dispatch claim; it will not be reclaimed automatically. Inspect and cancel the parent run. A replacement requires application-owned dispatch code and the original operational input.', [
            'child_run_id' => $childRunId,
            'parent_run_id' => $child['parent_run_id'] ?? null,
            'claimed_at' => $dispatchedAt->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $failure
     */
    public function markChildTerminalIfNeeded(string $childRunId, string $status, ?string $output, ?array $failure): void
    {
        $child = $this->durableRuns->childRunForChild($childRunId);

        if ($child === null || in_array($child['status'], ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $this->durableRuns->updateChildRun($childRunId, $status, $output, $failure);
        $parent = $this->durableRuns->find((string) $child['parent_run_id']);

        if ($parent !== null) {
            $this->reconcileTerminalChildrenForParent($parent);
        }
    }

    /**
     * @param  array<string, mixed>  $parent
     */
    public function reconcileTerminalChildrenForParent(array $parent): void
    {
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

                if (! $this->durableRuns->markChildTerminalEventDispatched((string) $child['child_run_id'])) {
                    return false;
                }

                $updated = $this->runs->requireRun($parent['run_id']);
                $this->outbox->enqueueStep($parent['run_id'], (int) $updated['next_step_index'], $updated['queue_connection'], $updated['queue_name']);

                return true;
            });

            if (! $released) {
                continue;
            }

            if ($child['status'] === 'completed') {
                $this->events->dispatch(new SwarmChildCompleted($parent['run_id'], (string) $child['child_run_id'], (string) $child['child_swarm_class']));
            } else {
                $this->events->dispatch(new SwarmChildFailed($parent['run_id'], (string) $child['child_run_id'], (string) $child['child_swarm_class'], is_array($child['failure'] ?? null) ? $child['failure'] : null));
            }
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
     * @return array{message?: string, class: class-string<Throwable>}
     */
    protected function failurePayload(Throwable $exception): array
    {
        /** @var array{message?: string, class: class-string<Throwable>} $payload */
        $payload = $this->capture->failureArray($exception);

        return $payload;
    }
}
