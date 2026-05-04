<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Events\SwarmSignalled;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaitTimedOut;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;

class DurableSignalHandler
{
    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected Dispatcher $events,
        protected SwarmCapture $capture,
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
        $run = $this->requireRun($runId);
        $capturedPayload = $this->durablePayload($payload);
        $signal = $this->durableRuns->recordSignal($runId, $name, $capturedPayload, $idempotencyKey);
        $accepted = false;
        $dispatchStep = null;

        if (($signal['duplicate'] ?? false) !== true && $run['status'] === 'waiting') {
            $accepted = $this->durableRuns->releaseWaitWithSignal($runId, $name, (int) $signal['id']);

            if ($accepted) {
                $context = $this->loadContext($runId);
                $signals = is_array($context->metadata['durable_signals'] ?? null) ? $context->metadata['durable_signals'] : [];
                $outcomes = is_array($context->metadata['durable_wait_outcomes'] ?? null) ? $context->metadata['durable_wait_outcomes'] : [];
                $signals[$name] = ['payload' => $capturedPayload, 'signal_id' => $signal['id']];
                $outcomes[$name] = ['status' => 'signalled', 'payload' => $capturedPayload, 'timed_out' => false];
                $context->mergeMetadata([
                    'durable_signals' => $signals,
                    'durable_wait_outcomes' => $outcomes,
                ]);
                $this->contextStore->put($this->capture->activeContext($context), $this->ttlSeconds());
                $this->historyStore->syncDurableState($runId, 'pending', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false);

                $updated = $this->requireRun($runId);
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
            executionMode: $this->publicLifecycleExecutionMode($run),
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
        $run = $this->requireRun($runId);
        $metadata = $this->durablePayload($metadata);
        $this->durableRuns->createWait($runId, $name, $reason, $timeoutSeconds, $metadata);

        $context = $this->loadContext($runId);
        $this->historyStore->syncDurableState($runId, 'waiting', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false);

        $this->events->dispatch(new SwarmWaiting(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            waitName: $name,
            reason: $reason,
            metadata: $this->capturedEventMetadata($context),
            executionMode: $this->publicLifecycleExecutionMode($run),
        ));
    }

    /**
     * @return array<string, mixed>
     */
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

    protected function durablePayload(mixed $payload): mixed
    {
        if ($this->capture->capturesInputs() && $this->capture->capturesOutputs()) {
            return $payload;
        }

        if (is_array($payload)) {
            return $this->redactArray($payload);
        }

        return SwarmCapture::REDACTED;
    }

    /**
     * @return array<string, mixed>
     */
    protected function capturedEventMetadata(RunContext $context): array
    {
        $metadata = $this->durablePayload($context->metadata);

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function publicLifecycleExecutionMode(array $run): string
    {
        if (($run['coordination_profile'] ?? CoordinationProfile::StepDurable->value) === CoordinationProfile::QueueHierarchicalParallel->value) {
            return ExecutionMode::Queue->value;
        }

        return ExecutionMode::Durable->value;
    }

    protected function ttlSeconds(): int
    {
        return (int) $this->config->get('swarm.context.ttl', 3600);
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    protected function redactArray(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $redacted[$key] = is_array($value) ? $this->redactArray($value) : SwarmCapture::REDACTED;
        }

        return $redacted;
    }
}
