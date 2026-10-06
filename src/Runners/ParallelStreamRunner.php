<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Concerns\MergesAgentUsage;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\HaltsSwarmExecution;
use BuiltByBerry\LaravelSwarm\Contracts\RecordsContextualRunFailure;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Exceptions\StructuredOutputStreamingException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmStreamProviderException;
use BuiltByBerry\LaravelSwarm\Memory\AgentVisibleMemoryView;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\Concerns\RecordsUnknownStreamEvents;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Parallel\ParallelProcessStreamTransport;
use BuiltByBerry\LaravelSwarm\Streaming\Parallel\ParallelStreamLimits;
use BuiltByBerry\LaravelSwarm\Support\AdHocSwarm;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManager;
use BuiltByBerry\LaravelSwarm\Support\NativeStepResultProjector;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\Error as ProviderStreamError;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @phpstan-import-type SwarmTaskInput from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 *
 * @internal
 */
final class ParallelStreamRunner
{
    use MergesAgentUsage;
    use RecordsUnknownStreamEvents;

    public function __construct(
        private ConfigRepository $config,
        private ContextStore $contextStore,
        private ArtifactRepository $artifactRepository,
        private RunHistoryStore $historyStore,
        private StreamEventStore $streamEvents,
        private Dispatcher $events,
        private ParallelRunner $parallel,
        private SwarmCapture $capture,
        private SwarmPayloadLimits $limits,
        private SwarmAttributeResolver $resolver,
        private SwarmAuditDispatcher $audit,
        private SwarmTelemetryDispatcher $telemetry,
        private SwarmGuardrailRunner $guardrails,
        private LoggerInterface $logger,
        private NativeInputManager $nativeInputs,
        private SwarmStepRecorder $steps,
        private SnapshotsMemory $snapshots,
        private AgentVisibleMemoryView $view,
        private ConcurrencyManager $concurrency,
        private ParallelProcessStreamTransport $transport,
        private ParallelStreamLimits $parallelStreamLimits,
        private NativeStepResultProjector $nativeResults,
    ) {}

    /** @param SwarmTaskInput $task */
    public function stream(Swarm $swarm, string|array|RunContext|AgentInput|UserMessage $task): StreamableSwarmResponse
    {
        if (! (bool) $this->config->get('swarm.streaming.parallel.enabled', false)) {
            throw new SwarmException('Live parallel streaming is disabled. Enable [swarm.streaming.parallel.enabled], or use prompt() for buffered parallel completion.');
        }
        if (! $this->concurrency->driver() instanceof ProcessDriver) {
            throw new SwarmException('Live parallel streaming requires Laravel\'s process concurrency driver; serial, fork, and custom drivers expose only buffered completion. Use prompt() or configure concurrency.default=process.');
        }

        $topology = $this->resolver->resolveTopology($swarm);
        if ($topology !== Topology::Parallel) {
            throw new SwarmException('The live parallel stream runner only accepts parallel swarms.');
        }

        $this->parallel->ensureAgentsAreContainerResolvable($swarm);
        $maxAgentExecutions = $this->resolver->resolveMaxAgentExecutions($swarm);
        $agents = array_slice($swarm->agents(), 0, $maxAgentExecutions);
        $parallelStreamLimits = $this->parallelStreamLimits->resolve();
        $maxBranches = $parallelStreamLimits['max_branches'];
        if (count($agents) > $maxBranches) {
            throw new SwarmException('Live parallel streaming was asked to open ['.count($agents)."] branches, above the configured [{$maxBranches}] per-stream branch-process limit.");
        }
        foreach ($agents as $index => $agent) {
            StructuredOutputStreamingException::guard($agent, "parallel:{$index}");
        }

        $timeoutSeconds = $this->resolver->resolveTimeoutSeconds($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $context = RunContext::fromTask($task);
        $this->checkInputPayload($task, $context);
        $this->parallel->ensureAdHocNativeSettingsAreDeclared($swarm, $context, $agents);
        $context->mergeMetadata(['swarm_class' => $swarm::class, 'topology' => $topology->value]);

        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: $topology,
            executionMode: ExecutionMode::Stream,
            deadlineMonotonic: (float) hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: $maxAgentExecutions,
            ttlSeconds: $contextTtl,
            leaseSeconds: null,
            executionToken: null,
            verifyOwnership: null,
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
            queueHierarchicalParallelCoordination: null,
        );

        try {
            $this->guardrails->validateInput($swarm, $context);
        } catch (Throwable $exception) {
            $this->recordPreflightFailure($state, $context, $swarm, $exception);
            throw $exception;
        }

        $startedAt = null;

        return new StreamableSwarmResponse(
            runId: $context->runId,
            generator: function () use ($state, $context, $contextTtl, $swarm, &$startedAt): \Generator {
                return yield from $this->execute($state, $context, $contextTtl, $swarm, $startedAt);
            },
            streamEvents: $this->streamEvents,
            ttlSeconds: $contextTtl,
            storesForReplay: (bool) $this->config->get('swarm.streaming.replay.enabled', false),
            replayFailurePolicy: (string) $this->config->get('swarm.streaming.replay.failure_policy', 'fail'),
            onReplayFailure: function (Throwable $exception) use ($state, $context, $contextTtl, $swarm, &$startedAt): SwarmStreamError {
                $sequence = 0;

                return $this->failStream($state, $context, $contextTtl, $swarm, $exception, $startedAt, MonotonicTime::now(), $sequence);
            },
            onAbandoned: function (SwarmException $exception) use ($state, $context, $contextTtl, $swarm, &$startedAt): void {
                $sequence = 0;
                $this->failStream($state, $context, $contextTtl, $swarm, $exception, $startedAt, MonotonicTime::now(), $sequence);
            },
            onAbandonmentFailure: function (Throwable $exception) use ($context): void {
                $this->logger->error('Parallel swarm stream abandonment could not be terminalized.', [
                    'run_id' => $context->runId,
                    'exception_class' => $exception::class,
                ]);
            },
        );
    }

    /**
     * @param-out float|null $startedAt
     *
     * @return \Generator<int, SwarmStreamEvent, mixed, SwarmResponse>
     */
    private function execute(SwarmExecutionState $state, RunContext $context, int $contextTtl, Swarm $swarm, ?float &$startedAt): \Generator
    {
        $parallelStreamLimits = $this->parallelStreamLimits->resolve();
        $historyStarted = false;
        $streamSequence = 0;
        $telemetryStarted = MonotonicTime::now();
        $failureBranch = null;
        $branchSequences = [];
        $attemptIds = [];
        $session = null;

        $this->historyStore->start($context->runId, $swarm::class, $state->topology->value, $this->capture->context($context), $context->metadata, $contextTtl);
        $historyStarted = true;

        try {
            $this->contextStore->put($this->capture->activeContext($context), $contextTtl);
            $this->events->dispatch(new SwarmStarted($context->runId, $swarm::class, $state->topology->value,
                $this->capture->applyInput($context->input, $context), $context->metadata, ExecutionMode::Stream->value));
            $this->audit->emit('run.started', [
                'run_id' => $context->runId,
                'parent_run_id' => $context->metadata['parent_run_id'] ?? null,
                'swarm_class' => $swarm::class,
                'topology' => $state->topology->value,
                'execution_mode' => ExecutionMode::Stream->value,
                'status' => 'started',
                ...$this->audit->metadata($context->metadata),
            ]);

            $start = new SwarmStreamStart(SwarmStreamEvent::newId(), $context->runId, $swarm::class,
                $state->topology->value, $this->capture->applyInput($context->input, $context), $context->metadata,
                SwarmStreamEvent::timestamp());
            $this->recordTelemetry($state, $swarm, $start, $streamSequence, $telemetryStarted);
            yield $start;
            $startedAt = MonotonicTime::now();

            $agents = array_slice($swarm->agents(), 0, $state->maxAgentExecutions);
            $input = $context->prompt();
            $workers = [];
            $snapshots = [];
            $contextPayload = $context->toQueuePayload();
            $attemptIdList = $state->nativeSettingsAttempt->ids();
            $workerSettings = [
                'capture' => [
                    'inputs' => $this->capture->inputsDecision($context)->name,
                    'outputs' => $this->capture->outputsDecision($context)->name,
                ],
                'citations' => [
                    'max_count' => (int) $this->config->get('swarm.citations.max_count', 256),
                    'max_bytes' => (int) $this->config->get('swarm.citations.max_bytes', 262144),
                ],
                'provider_tools' => [
                    'max_event_bytes' => (int) $this->config->get('swarm.provider_tools.max_event_bytes', 65536),
                    'max_step_bytes' => (int) $this->config->get('swarm.provider_tools.max_step_bytes', 262144),
                    'max_depth' => (int) $this->config->get('swarm.provider_tools.max_depth', 32),
                ],
                'native_results' => $this->nativeResults->resolvedLimits(),
            ];
            $maxAgentExecutions = $state->maxAgentExecutions;
            $adHoc = $swarm instanceof AdHocSwarm;
            $runId = $context->runId;
            $swarmClass = $swarm::class;

            foreach ($agents as $index => $agent) {
                $branchId = "parallel:{$index}";
                $attemptIds[$branchId] = SwarmStreamEvent::newId();
                $branchSequences[$branchId] = 1;
                $this->steps->started($state, $index, $agent::class, $input);
                $snapshots[$index] = $this->snapshots->snapshot(
                    $runId,
                    $index,
                    $this->view->present($swarm, $context, $agent),
                );

                $stepStart = (new SwarmStepStart(
                    id: SwarmStreamEvent::newId(),
                    runId: $runId,
                    stepIndex: $index,
                    agentClass: $agent::class,
                    agent: class_basename($agent),
                    input: $this->capture->applyInput($input, $context),
                    timestamp: SwarmStreamEvent::timestamp(),
                ))->withNodeId($branchId)->withBranchIdentity($branchId, $attemptIds[$branchId], 0);
                $this->recordTelemetry($state, $swarm, $stepStart, $streamSequence, $telemetryStarted);
                yield $stepStart;

                $agentClass = $agent::class;
                $snapshotEntries = $snapshots[$index]->entries;
                $workers[$branchId] = static function (string $endpoint, string $token, float $deadline, int $maxFrameBytes) use (
                    $branchId, $runId, $swarmClass, $agentClass, $index, $adHoc, $input, $contextPayload,
                    $attemptIdList, $snapshotEntries, $contextTtl, $maxAgentExecutions, $workerSettings
                ): array {
                    return Container::getInstance()->make(ParallelStreamBranchWorker::class)->run(
                        $endpoint, $token, $deadline, $maxFrameBytes, $branchId, $runId, $swarmClass,
                        $agentClass, $index, $adHoc, $input, $contextPayload, $attemptIdList, $snapshotEntries,
                        $contextTtl, $maxAgentExecutions, $workerSettings,
                    );
                };
            }

            $session = $this->transport->start(
                $workers,
                $state->deadlineMonotonic,
                $parallelStreamLimits['max_frame_bytes'],
                $parallelStreamLimits['cancel_grace_milliseconds'],
            );
            $multiplexed = $session->events();
            $steps = [];
            $usage = [];
            $outputs = [];
            foreach ($multiplexed as $envelope) {
                $branchId = $envelope['branch_id'];
                $failureBranch = $branchId;
                $branchIndex = (int) substr($branchId, strlen('parallel:'));

                if (($envelope['terminal'] ?? false) === true) {
                    $row = $envelope['payload'];
                    if (! isset($agents[$branchIndex])) {
                        throw new SwarmException("Parallel stream branch [{$branchId}] did not return a successful terminal outcome.");
                    }
                    if (($row['ok'] ?? null) !== true) {
                        $failedUsage = is_array($row['usage'] ?? null) ? $row['usage'] : [];
                        $usage = $this->mergeUsageReport($usage, $failedUsage);
                        $context->mergeMetadata(['usage' => $usage]);
                        ConcurrentAgentResult::throwFailureDescriptor(
                            is_array($row['failure'] ?? null) ? $row['failure'] : [],
                        );
                    }
                    $agent = $agents[$branchIndex];
                    $this->guardrails->validateStep(
                        $swarm,
                        GuardrailStepContext::fromState($state, $branchIndex, $agent::class, $input, (string) ($row['output'] ?? ''), []),
                        $context,
                    );

                    $consumed = is_array($row['native_settings_consumed'] ?? null)
                        ? array_values(array_filter($row['native_settings_consumed'], 'is_string'))
                        : [];
                    $state->nativeSettingsAttempt->merge($consumed);
                    foreach (is_array($row['tool_calls'] ?? null) ? $row['tool_calls'] : [] as $toolCall) {
                        if (is_array($toolCall)) {
                            $snapshots[$branchIndex] = $this->snapshots->appendToolCall(
                                $snapshots[$branchIndex],
                                $this->normalizeToolCall($toolCall),
                            );
                        }
                    }
                    $unknown = is_array($row['unknown_event_classes'] ?? null)
                        ? array_fill_keys(array_filter($row['unknown_event_classes'], 'is_string'), true)
                        : [];
                    $this->breadcrumbUnknownStreamEvents($unknown, $runId, $branchIndex);

                    $stepUsage = is_array($row['usage'] ?? null) ? $row['usage'] : [];
                    $citationEvidence = CitationEvidence::fromArray(is_array($row['citation_evidence'] ?? null) ? $row['citation_evidence'] : []);
                    $nativeResult = is_array($row['native_result'] ?? null)
                        ? NativeStepResult::fromArray($row['native_result'])
                        : NativeStepResult::unavailable(['missing']);
                    $step = $this->steps->completed(
                        state: $state,
                        index: $branchIndex,
                        agentClass: $agent::class,
                        input: $input,
                        output: (string) ($row['output'] ?? ''),
                        usage: $stepUsage,
                        durationMs: (int) ($row['duration_ms'] ?? 1),
                        updateContext: false,
                        storeContext: false,
                        storeArtifacts: false,
                        citationEvidence: $citationEvidence,
                        nativeResult: $nativeResult,
                    );
                    $steps[$branchIndex] = $step;
                    $outputs[$branchIndex] = $step->output;
                    $usage = $this->mergeUsageReport($usage, $stepUsage);

                    $stepEnd = (new SwarmStepEnd(
                        citationEvidence: $this->capture->citationEvidence($citationEvidence, $context),
                        id: SwarmStreamEvent::newId(),
                        runId: $runId,
                        stepIndex: $branchIndex,
                        agentClass: $agent::class,
                        agent: class_basename($agent),
                        output: $this->capture->applyOutput($step->output, $context),
                        durationMs: (int) ($row['duration_ms'] ?? 1),
                        metadata: ['usage' => $stepUsage],
                        timestamp: SwarmStreamEvent::timestamp(),
                        nativeResult: $this->capture->nativeResultForStreamEvent($nativeResult, $context),
                    ))->withNodeId($branchId)->withBranchIdentity(
                        $branchId,
                        $attemptIds[$branchId],
                        $branchSequences[$branchId]++,
                    );
                    $this->recordTelemetry($state, $swarm, $stepEnd, $streamSequence, $telemetryStarted);
                    yield $stepEnd;
                    $failureBranch = null;

                    continue;
                }

                $event = SwarmStreamEvent::fromArray($envelope['payload']);
                if (($event->toArray()['run_id'] ?? null) !== $runId) {
                    throw new SwarmException("Parallel stream branch [{$branchId}] returned an event for a different run.");
                }
                if (($event->toArray()['step_index'] ?? $branchIndex) !== $branchIndex) {
                    throw new SwarmException("Parallel stream branch [{$branchId}] returned an event for a different branch index.");
                }
                $event->withNodeId($branchId)->withBranchIdentity(
                    $branchId,
                    $attemptIds[$branchId],
                    $branchSequences[$branchId]++,
                );
                $this->recordTelemetry($state, $swarm, $event, $streamSequence, $telemetryStarted);
                yield $event;
                $failureBranch = null;
            }
            if (count($steps) !== count($agents)) {
                throw new SwarmException('Parallel stream did not return one successful terminal outcome per branch.');
            }

            ksort($steps);
            ksort($outputs);
            $steps = array_values($steps);
            $outputs = array_values($outputs);

            $combined = implode("\n\n", $outputs);
            $context->mergeData(['last_output' => $combined, 'steps' => count($steps)])
                ->mergeMetadata(['topology' => $state->topology->value, 'usage' => $usage]);
            $this->contextStore->put($this->capture->activeContext($context), $contextTtl);
            $this->artifactRepository->storeMany($runId, $context->artifacts, $contextTtl);

            $response = new SwarmResponse(
                output: $combined,
                steps: $steps,
                citationEvidence: CitationEvidence::combine(array_map(static fn ($step) => $step->citationEvidence, $steps)),
                usage: $usage,
                context: $context,
                artifacts: $context->artifacts,
                metadata: array_merge($context->metadata, ['run_id' => $runId, 'topology' => $state->topology->value]),
            );
            $this->guardrails->validateOutput($swarm, $context, $response->output);
            $captured = $this->limits->response($this->capture->response($response));
            $this->nativeInputs->commitTerminal($context, $state->nativeSettingsAttempt,
                fn () => $this->historyStore->complete($runId, $captured, $contextTtl));
            $this->contextStore->put($this->capture->terminalContext($context), $contextTtl);
            $this->events->dispatch(new SwarmCompleted($runId, $swarm::class, $state->topology->value,
                $this->capture->applyOutput($captured->output, $context), MonotonicTime::elapsedMilliseconds($startedAt),
                $captured->metadata, $captured->artifacts, ExecutionMode::Stream->value));
            $this->audit->emit('run.completed', [
                'run_id' => $runId,
                'parent_run_id' => $context->metadata['parent_run_id'] ?? null,
                'swarm_class' => $swarm::class,
                'topology' => $state->topology->value,
                'execution_mode' => ExecutionMode::Stream->value,
                'status' => 'completed',
                'duration_ms' => MonotonicTime::elapsedMilliseconds($startedAt),
                ...$this->audit->metadata($captured->metadata),
            ]);

            $end = new SwarmStreamEnd(
                citationEvidence: $captured->citationEvidence,
                id: SwarmStreamEvent::newId(),
                runId: $runId,
                output: $this->capture->applyOutput($captured->output, $context),
                usage: $captured->usage,
                metadata: $captured->metadata,
                timestamp: SwarmStreamEvent::timestamp(),
            );
            $this->recordTelemetry($state, $swarm, $end, $streamSequence, $telemetryStarted);
            yield $end;

            return $response;
        } catch (Throwable $exception) {
            $failureBranch ??= $session?->failureBranchId;
            $branchIdentity = is_string($failureBranch) && isset($attemptIds[$failureBranch], $branchSequences[$failureBranch])
                ? [
                    'branch_id' => $failureBranch,
                    'attempt_id' => $attemptIds[$failureBranch],
                    'branch_sequence' => $branchSequences[$failureBranch]++,
                ]
                : null;
            $error = $this->failStream($state, $context, $contextTtl, $swarm, $exception, $startedAt,
                $telemetryStarted, $streamSequence, $historyStarted, false, $branchIdentity);
            $this->recordTelemetry($state, $swarm, $error, $streamSequence, $telemetryStarted);
            yield $error;
            NativeOutcomeValidator::rethrowIfUnsupported($exception);
            throw $exception;
        }
    }

    private function recordPreflightFailure(SwarmExecutionState $state, RunContext $context, Swarm $swarm, Throwable $exception): void
    {
        if ($exception instanceof GuardrailViolation) {
            $context->mergeMetadata($exception->safeContextMetadata());
        }
        $this->historyStore->recordPreflightFailure($context->runId, $swarm::class, $state->topology->value,
            $context, $context->metadata, $exception, $state->ttlSeconds);
        $this->events->dispatch(new SwarmFailed($context->runId, $swarm::class, $state->topology->value,
            $this->capture->failureException($exception), 0, $context->metadata, ExecutionMode::Stream->value, $exception::class));
        if (! $exception instanceof HaltsSwarmExecution) {
            $this->emitRunFailedAudit($state, $context, $swarm, $exception::class, 0, $context->metadata);
        }
    }

    /** @param array{branch_id: string, attempt_id: string, branch_sequence: int}|null $branchIdentity */
    private function failStream(SwarmExecutionState $state, RunContext $context, int $ttl, Swarm $swarm,
        Throwable $exception, ?float $startedAt, float $telemetryStarted, int &$sequence,
        bool $historyStarted = true, bool $recordTelemetry = true, ?array $branchIdentity = null): SwarmStreamError
    {
        if ($exception instanceof GuardrailViolation) {
            $context->mergeMetadata($exception->safeContextMetadata());
        }
        if (is_array($branchIdentity)) {
            $context->mergeMetadata($branchIdentity);
        }
        $duration = $startedAt !== null ? MonotonicTime::elapsedMilliseconds($startedAt) : 1;
        $failureMetadata = $exception instanceof SwarmStreamProviderException
            ? array_merge($context->metadata, ['provider_error' => $exception->metadata], $branchIdentity ?? [])
            : array_merge($context->metadata, $branchIdentity ?? []);
        if ($historyStarted) {
            if ($this->historyStore instanceof RecordsContextualRunFailure) {
                $this->historyStore->failWithMetadata($context->runId, $exception, $failureMetadata, $ttl);
            } else {
                $this->historyStore->fail($context->runId, $exception, $ttl);
            }
        } else {
            $this->historyStore->recordPreflightFailure($context->runId, $swarm::class, $state->topology->value,
                $context, $failureMetadata, $exception, $ttl);
        }
        $this->contextStore->put($this->capture->terminalContext($context), $ttl);
        $failureClass = $exception instanceof SwarmStreamProviderException ? ProviderStreamError::class : $exception::class;
        $this->events->dispatch(new SwarmFailed($context->runId, $swarm::class, $state->topology->value,
            $this->capture->failureException($exception), $duration, $failureMetadata,
            ExecutionMode::Stream->value, $failureClass));
        if (! $exception instanceof HaltsSwarmExecution) {
            $this->emitRunFailedAudit($state, $context, $swarm, $failureClass, $duration, $failureMetadata);
        }
        $event = new SwarmStreamError(
            $exception instanceof SwarmStreamProviderException ? $exception->eventId : SwarmStreamEvent::newId(),
            $context->runId,
            $this->capture->applyFailureMessage($exception, $context),
            $failureClass,
            $exception instanceof SwarmStreamProviderException ? $exception->recoverable : false,
            $failureMetadata,
            $exception instanceof SwarmStreamProviderException ? $exception->timestamp : SwarmStreamEvent::timestamp(),
        );
        if ($exception instanceof SwarmStreamProviderException && is_string($exception->invocationId)) {
            $event->withInvocationId($exception->invocationId);
        }
        if (is_array($branchIdentity)) {
            $event->withNodeId($branchIdentity['branch_id'])->withBranchIdentity(
                $branchIdentity['branch_id'],
                $branchIdentity['attempt_id'],
                $branchIdentity['branch_sequence'],
            );
        }
        if ($recordTelemetry) {
            $this->recordTelemetry($state, $swarm, $event, $sequence, $telemetryStarted);
        }

        return $event;
    }

    /** @param array<string, mixed> $metadata */
    private function emitRunFailedAudit(SwarmExecutionState $state, RunContext $context, Swarm $swarm,
        string $exceptionClass, int $duration, array $metadata): void
    {
        $this->audit->emit('run.failed', [
            'run_id' => $context->runId,
            'parent_run_id' => $context->metadata['parent_run_id'] ?? null,
            'swarm_class' => $swarm::class,
            'topology' => $state->topology->value,
            'execution_mode' => ExecutionMode::Stream->value,
            'status' => 'failed',
            'exception_class' => $exceptionClass,
            'duration_ms' => $duration,
            ...$this->branchFailureIdentity($metadata),
            ...$this->audit->metadata($metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{branch_id: string, attempt_id: string, branch_sequence: int}|array{}
     */
    private function branchFailureIdentity(array $metadata): array
    {
        return is_string($metadata['branch_id'] ?? null)
            && is_string($metadata['attempt_id'] ?? null)
            && is_int($metadata['branch_sequence'] ?? null)
            ? [
                'branch_id' => $metadata['branch_id'],
                'attempt_id' => $metadata['attempt_id'],
                'branch_sequence' => $metadata['branch_sequence'],
            ]
            : [];
    }

    private function recordTelemetry(SwarmExecutionState $state, Swarm $swarm, SwarmStreamEvent $event, int &$sequence, float $started): void
    {
        $this->telemetry->emit('stream.event', [
            'run_id' => $state->context->runId,
            'parent_run_id' => $state->context->metadata['parent_run_id'] ?? null,
            'swarm_class' => $swarm::class,
            'topology' => $state->topology->value,
            'execution_mode' => $state->executionMode->value,
            'event_type' => $event->toArray()['type'] ?? 'unknown',
            'sequence_index' => $sequence++,
            'duration_ms' => MonotonicTime::elapsedMilliseconds($started),
            'is_replay' => false,
            'status' => 'streaming',
            'branch_id' => $event->branchId,
            'attempt_id' => $event->attemptId,
            'branch_sequence' => $event->branchSequence,
        ]);
    }

    /** @param SwarmTaskInput $task */
    private function checkInputPayload(string|array|RunContext|AgentInput|UserMessage $task, RunContext $context): void
    {
        $task instanceof RunContext ? $this->limits->checkContextInput($context) : $this->limits->checkInput($context->input);
    }

    /**
     * @param  array<mixed>  $toolCall
     * @return array{name: string, arguments: array<string, mixed>, result: mixed, id?: string|null, result_id?: string|null}
     */
    private function normalizeToolCall(array $toolCall): array
    {
        $arguments = [];
        foreach (is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [] as $key => $value) {
            if (is_string($key)) {
                $arguments[$key] = $value;
            }
        }

        return [
            'name' => is_string($toolCall['name'] ?? null) ? $toolCall['name'] : '',
            'arguments' => $arguments,
            'result' => $toolCall['result'] ?? null,
            'id' => is_string($toolCall['id'] ?? null) ? $toolCall['id'] : null,
            'result_id' => is_string($toolCall['result_id'] ?? null) ? $toolCall['result_id'] : null,
        ];
    }
}
