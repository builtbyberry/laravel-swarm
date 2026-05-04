<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmSignalled;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Contracts\Events\Dispatcher;

class DurableSignalHandler
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected Dispatcher $events,
        protected SwarmCapture $capture,
        protected DurableRunContext $runs,
        protected DurablePayloadCapture $payloads,
    ) {}

    /**
     * Process an inbound signal against a durable run.
     *
     * Returns the result and, when a waiting run was released, the updated run
     * row so the caller can dispatch the next step job.
     *
     * @return array{result: DurableSignalResult, dispatchStep: array{runId: string, stepIndex: int, connection: ?string, queue: ?string}|null}
     */
    public function signal(string $runId, string $name, mixed $payload = null, ?string $idempotencyKey = null): array
    {
        $run = $this->runs->requireRun($runId);
        $capturedPayload = $this->payloads->payload($payload);
        $signal = $this->durableRuns->recordSignal($runId, $name, $capturedPayload, $idempotencyKey);
        $accepted = false;
        $dispatchStep = null;

        if (($signal['duplicate'] ?? false) !== true && $run['status'] === 'waiting') {
            $accepted = $this->durableRuns->releaseWaitWithSignal($runId, $name, (int) $signal['id']);

            if ($accepted) {
                $context = $this->runs->loadContext($runId);
                $signals = is_array($context->metadata['durable_signals'] ?? null) ? $context->metadata['durable_signals'] : [];
                $outcomes = is_array($context->metadata['durable_wait_outcomes'] ?? null) ? $context->metadata['durable_wait_outcomes'] : [];
                $signals[$name] = ['payload' => $capturedPayload, 'signal_id' => $signal['id']];
                $outcomes[$name] = ['status' => 'signalled', 'payload' => $capturedPayload, 'timed_out' => false];
                $context->mergeMetadata([
                    'durable_signals' => $signals,
                    'durable_wait_outcomes' => $outcomes,
                ]);
                $this->contextStore->put($this->capture->activeContext($context), $this->runs->ttlSeconds());
                $this->historyStore->syncDurableState($runId, 'pending', $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false);

                $updated = $this->runs->requireRun($runId);
                if ($updated['status'] === 'pending') {
                    $dispatchStep = [
                        'runId' => $runId,
                        'stepIndex' => (int) $updated['next_step_index'],
                        'connection' => $updated['queue_connection'],
                        'queue' => $updated['queue_name'],
                    ];
                }
            }
        }

        $this->events->dispatch(new SwarmSignalled(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            signalName: $name,
            accepted: $accepted,
            executionMode: $this->runs->publicLifecycleExecutionMode($run),
        ));

        $result = new DurableSignalResult(
            runId: $runId,
            name: $name,
            status: $accepted ? 'accepted' : (string) ($signal['status'] ?? 'recorded'),
            accepted: $accepted,
            duplicate: (bool) ($signal['duplicate'] ?? false),
            signal: $signal,
        );

        return ['result' => $result, 'dispatchStep' => $dispatchStep];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function wait(string $runId, string $name, ?string $reason = null, ?int $timeoutSeconds = null, array $metadata = []): void
    {
        $run = $this->runs->requireRun($runId);
        $metadata = $this->payloads->payload($metadata);
        $this->durableRuns->createWait($runId, $name, $reason, $timeoutSeconds, $metadata);

        $context = $this->runs->loadContext($runId);
        $this->historyStore->syncDurableState($runId, 'waiting', $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false);

        $this->events->dispatch(new SwarmWaiting(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            waitName: $name,
            reason: $reason,
            metadata: $this->payloads->eventMetadata($context),
            executionMode: $this->runs->publicLifecycleExecutionMode($run),
        ));
    }
}
