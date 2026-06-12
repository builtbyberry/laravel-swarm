<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Concerns\MergesAgentUsage;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\StreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmStreamProviderException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Memory\AgentVisibleMemoryView;
use BuiltByBerry\LaravelSwarm\Memory\MemoryReplayCoordinator;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Memory\SnapshotToolCallNormalizer;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Generator;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Streaming\Events\Error as ProviderStreamError;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal
 */
class SequentialRunner
{
    use MergesAgentUsage;

    public function __construct(
        protected SwarmStepRecorder $steps,
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
        protected SwarmGuardrailRunner $guardrails,
        protected SnapshotsMemory $snapshots,
        protected AgentVisibleMemoryView $view,
        protected MemoryReplayCoordinator $coordinator,
        protected StreamStepCheckpointStore $checkpoints,
        protected LoggerInterface $logger,
    ) {}

    public function run(SwarmExecutionState $state): SwarmResponse
    {
        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);
        $steps = [];
        $mergedUsage = [];

        foreach ($agents as $index => $agent) {
            if (hrtime(true) >= $state->deadlineMonotonic) {
                throw new SwarmTimeoutException('The swarm exceeded its configured timeout while running sequentially.');
            }

            $step = $this->runSingleStep($state, $index);

            $steps[] = $step;
            $mergedUsage = $this->mergeUsage($mergedUsage, is_array($step->metadata['usage'] ?? null) ? $step->metadata['usage'] : []);
        }

        return new SwarmResponse(
            output: (string) ($state->context->data['last_output'] ?? $state->context->input),
            steps: $steps,
            usage: $mergedUsage,
            context: $state->context,
            artifacts: $state->context->artifacts,
            metadata: [
                'run_id' => $state->context->runId,
                'topology' => $state->topology->value,
            ],
        );
    }

    /**
     * @return Generator<int, SwarmStreamEvent, mixed, void>
     */
    public function stream(SwarmExecutionState $state): Generator
    {
        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);
        $lastIndex = count($agents) - 1;
        $mergedUsage = [];

        // Resolve the frozen-view replay mode once: it is invariant for the
        // swarm class across the whole run (attribute-over-config), so gating
        // the per-step checkpoint probe/record on it avoids a ReflectionClass
        // allocation per step in this hot loop.
        $replayEnabled = $this->coordinator->replayEnabled($state->swarm::class);

        // Accumulate best-effort checkpoint-write failures and report ONE
        // warning at stream end (in the finally), not one per step — a systemic
        // DB outage would otherwise flood logs with a line per non-final step.
        /** @var array<int, int> $checkpointWriteFailures */
        $checkpointWriteFailures = [];
        $firstCheckpointFailureClass = null;

        // Publish the active run so an agent's RemembersRunContext trait can
        // read the propagation-policy view during messages(). Set once for the
        // generator's lifetime (run id, swarm, and context are constant across
        // steps); cleared in finally so it never leaks past the stream.
        ActiveRunContext::enter($state->context->runId, $state->swarm::class, $state->context);

        try {
            foreach ($agents as $index => $agent) {
                if (hrtime(true) >= $state->deadlineMonotonic) {
                    throw new SwarmTimeoutException('The swarm exceeded its configured timeout while streaming sequentially.');
                }

                $input = $state->context->prompt();
                $agentName = class_basename($agent::class);

                $this->steps->started($state, $index, $agent::class, $input);

                $isFinal = $index === $lastIndex;

                // Idempotent multi-step resume (issue #202): on a crash-resume
                // re-run, a completed non-final step is skipped entirely — its
                // provider is not re-invoked and its tool side effects do not
                // re-fire. Probe the per-step checkpoint BEFORE freezing a
                // snapshot so the skip path never overwrites the prior frozen
                // row. Gated on the same frozen-view replay mode as #192, so
                // fresh_execution swarms are unaffected. The final streamed step
                // is never checkpoint-skipped — it always replays from its frozen
                // snapshot (the #192 guarantee).
                $resumeCheckpoint = (! $isFinal && $replayEnabled)
                    ? $this->checkpoints->find($state->context->runId, $index)
                    : null;

                yield new SwarmStepStart(
                    id: SwarmStreamEvent::newId(),
                    runId: $state->context->runId,
                    stepIndex: $index,
                    agentClass: $agent::class,
                    agent: $agentName,
                    input: $this->capture->applyInput($input, $state->context),
                    timestamp: SwarmStreamEvent::timestamp(),
                );

                $startedAt = MonotonicTime::now();
                $durationMs = null;
                $stepUsage = [];

                if ($resumeCheckpoint !== null) {
                    // Skip path: rehydrate the recorded output + usage instead of
                    // invoking the agent. completed() re-seeds last_output/steps
                    // into the context so the next step's prompt() is
                    // byte-identical, and we re-run the step guardrails on the
                    // rehydrated output for parity with a fresh run. storeArtifacts
                    // is false: the artifact was already persisted on the original
                    // attempt and swarm_artifacts has no unique key, so re-storing
                    // would duplicate the row; the in-memory addArtifact() inside
                    // completed() still runs so the response artifact list is
                    // correct.
                    $output = (string) $resumeCheckpoint->output;
                    $stepUsage = $resumeCheckpoint->usage;

                    $this->guardrails->validateStep(
                        $state->swarm,
                        GuardrailStepContext::fromState($state, $index, $agent::class, $input, $output, []),
                        $state->context,
                    );

                    $step = $this->steps->completed(
                        state: $state,
                        index: $index,
                        agentClass: $agent::class,
                        input: $input,
                        output: $output,
                        usage: $stepUsage,
                        durationMs: $durationMs = MonotonicTime::elapsedMilliseconds($startedAt),
                        storeArtifacts: false,
                    );
                } elseif ($isFinal) {
                    // The final (streamed) step opens a snapshot-backed replay
                    // boundary so a crash-resume re-run replays byte-identically:
                    // begin() detects a prior frozen snapshot, swaps SwarmMemory to
                    // the frozen view, and returns it for resetToolCalls() below.
                    // First-attempt streamed steps take the fresh-execution path
                    // and freeze a new snapshot. The binding is restored in the
                    // streamed step's finally, so the swap never leaks past the
                    // agent invocation.
                    $boundary = $this->coordinator->begin($state->swarm::class, $state->context->runId, $index);

                    $snapshot = $boundary->isReplay()
                        ? $this->snapshots->resetToolCalls($boundary->snapshot)
                        : $this->snapshots->snapshot(
                            $state->context->runId,
                            $index,
                            $this->view->present($state->swarm, $state->context, $agent),
                        );

                    $stream = $agent->stream($input);
                    $output = '';
                    /** @var array<string, ToolCallData> $pendingToolCalls */
                    $pendingToolCalls = [];

                    try {
                        foreach ($stream as $event) {
                            if ($event instanceof TextDelta) {
                                $output .= $event->delta;
                                $swarmEvent = new SwarmTextDelta(
                                    id: $event->id,
                                    runId: $state->context->runId,
                                    stepIndex: $index,
                                    agentClass: $agent::class,
                                    delta: $this->capture->applyOutput($event->delta, $state->context),
                                    timestamp: $event->timestamp,
                                );
                                $this->syncInvocationId($swarmEvent, $event->invocationId);

                                yield $swarmEvent;
                            } elseif ($event instanceof TextEnd) {
                                $swarmEvent = new SwarmTextEnd(
                                    id: $event->id,
                                    runId: $state->context->runId,
                                    stepIndex: $index,
                                    agentClass: $agent::class,
                                    messageId: $event->messageId,
                                    timestamp: $event->timestamp,
                                );
                                $this->syncInvocationId($swarmEvent, $event->invocationId);

                                yield $swarmEvent;
                            } elseif ($event instanceof ReasoningDelta) {
                                $swarmEvent = new SwarmReasoningDelta(
                                    id: $event->id,
                                    runId: $state->context->runId,
                                    stepIndex: $index,
                                    agentClass: $agent::class,
                                    reasoningId: $event->reasoningId,
                                    delta: $this->capture->applyOutput($event->delta, $state->context),
                                    timestamp: $event->timestamp,
                                    summary: $this->captureReasoningSummary($event->summary, $state->context),
                                );
                                $this->syncInvocationId($swarmEvent, $event->invocationId);

                                yield $swarmEvent;
                            } elseif ($event instanceof ReasoningEnd) {
                                $swarmEvent = new SwarmReasoningEnd(
                                    id: $event->id,
                                    runId: $state->context->runId,
                                    stepIndex: $index,
                                    agentClass: $agent::class,
                                    reasoningId: $event->reasoningId,
                                    timestamp: $event->timestamp,
                                    summary: $this->captureReasoningSummary($event->summary, $state->context),
                                );
                                $this->syncInvocationId($swarmEvent, $event->invocationId);

                                yield $swarmEvent;
                            } elseif ($event instanceof ToolCall) {
                                // Hold the call until we see its matching ToolResult so the
                                // snapshot row records a paired input/output entry, then a
                                // single appendToolCall persists the finalized pair. This
                                // keeps the snapshot row write count proportional to tool
                                // results, not events.
                                $pendingToolCalls[$event->toolCall->id] = $event->toolCall;

                                $swarmEvent = new SwarmToolCall(
                                    id: $event->id,
                                    runId: $state->context->runId,
                                    stepIndex: $index,
                                    agentClass: $agent::class,
                                    toolCall: $this->captureToolCall($event->toolCall, $state->context),
                                    timestamp: $event->timestamp,
                                );
                                $this->syncInvocationId($swarmEvent, $event->invocationId);

                                yield $swarmEvent;
                            } elseif ($event instanceof ToolResult) {
                                $matchedCallId = $event->toolResult->id;
                                $matchedCall = $pendingToolCalls[$matchedCallId] ?? null;

                                if ($matchedCall !== null) {
                                    unset($pendingToolCalls[$matchedCallId]);
                                    $snapshot = $this->snapshots->appendToolCall(
                                        $snapshot,
                                        SnapshotToolCallNormalizer::entry($matchedCall, $event->toolResult),
                                    );
                                }

                                $swarmEvent = new SwarmToolResult(
                                    id: $event->id,
                                    runId: $state->context->runId,
                                    stepIndex: $index,
                                    agentClass: $agent::class,
                                    toolResult: $this->captureToolResult($event->toolResult, $state->context),
                                    successful: $event->successful,
                                    error: $this->captureToolError($event->error, $state->context),
                                    timestamp: $event->timestamp,
                                );
                                $this->syncInvocationId($swarmEvent, $event->invocationId);

                                yield $swarmEvent;
                            } elseif ($event instanceof StreamEnd) {
                                $stepUsage = $event->usage->toArray();
                            } elseif ($event instanceof ProviderStreamError) {
                                throw new SwarmStreamProviderException(
                                    message: $event->message,
                                    eventId: $event->id,
                                    invocationId: $event->invocationId,
                                    recoverable: $event->recoverable,
                                    metadata: $this->captureProviderErrorMetadata($event),
                                    timestamp: $event->timestamp,
                                    providerErrorType: $event->type,
                                );
                            }
                        }
                    } finally {
                        // Flush any tool calls without a matching ToolResult into
                        // the snapshot. This runs on the happy path (calls left
                        // pending at stream end) AND on abandonment (the generator
                        // was torn down mid-stream by a worker crash, so the
                        // `foreach` never reached `StreamEnd`). Doing it in
                        // `finally` makes the append crash-safe: an in-flight call
                        // is persisted with result=null instead of being lost, so
                        // the frozen snapshot is a faithful record of every tool
                        // the agent invoked and replay can rebuild byte-identically.
                        foreach ($pendingToolCalls as $unpairedCall) {
                            $snapshot = $this->snapshots->appendToolCall(
                                $snapshot,
                                SnapshotToolCallNormalizer::entry($unpairedCall),
                            );
                        }

                        // Restore the live SwarmMemory binding even if the stream
                        // was abandoned mid-flight. No-op on the fresh-execution
                        // path (begin() never swapped).
                        $this->coordinator->end($boundary);
                    }

                    $durationMs = MonotonicTime::elapsedMilliseconds($startedAt);
                    $this->guardrails->validateStep(
                        $state->swarm,
                        GuardrailStepContext::fromState($state, $index, $agent::class, $input, $output, []),
                        $state->context,
                    );
                    $step = $this->steps->completed(
                        state: $state,
                        index: $index,
                        agentClass: $agent::class,
                        input: $input,
                        output: $output,
                        usage: $stepUsage,
                        durationMs: $durationMs,
                    );
                } else {
                    // Non-final, fresh execution: freeze the agent-visible view
                    // (always a new snapshot — only the final step ever replays a
                    // prior one) and run the agent.
                    $snapshot = $this->snapshots->snapshot(
                        $state->context->runId,
                        $index,
                        $this->view->present($state->swarm, $state->context, $agent),
                    );

                    $response = $agent->prompt($input);
                    $output = (string) $response;
                    $stepUsage = $this->usageFromResponse($response);
                    $this->appendResponseToolCalls($snapshot, $response);

                    $this->guardrails->validateStep(
                        $state->swarm,
                        GuardrailStepContext::fromState($state, $index, $agent::class, $input, $output, []),
                        $state->context,
                    );

                    $step = $this->steps->completed(
                        state: $state,
                        index: $index,
                        agentClass: $agent::class,
                        input: $input,
                        output: $output,
                        usage: $stepUsage,
                        durationMs: $durationMs = MonotonicTime::elapsedMilliseconds($startedAt),
                    );

                    // Record the per-step checkpoint AFTER the step fully
                    // completed and guardrails passed — the post-completion write
                    // is the completion marker that lets a resume skip this step.
                    // Gated on replay so fresh_execution mode writes nothing. The
                    // write is best-effort: the step is already done and its side
                    // effects fired, so a checkpoint-store failure must not abort
                    // the stream — it only means this step won't be skippable on a
                    // future resume. Accumulate the failure and report it once at
                    // stream end (see the finally) rather than logging per step.
                    // We capture only the step index + first exception CLASS, never
                    // the exception message — a driver QueryException can echo bound
                    // params (the raw output, plaintext when encryption is off).
                    if ($replayEnabled) {
                        try {
                            $this->checkpoints->record($state->context->runId, $index, $output, $stepUsage);
                        } catch (Throwable $exception) {
                            $checkpointWriteFailures[] = $index;
                            $firstCheckpointFailureClass ??= $exception::class;
                        }
                    }
                }

                $mergedUsage = $this->mergeUsage($mergedUsage, $stepUsage);
                $stepOutput = $this->capture->applyOutput((string) ($step->artifacts[0]->content ?? $output), $state->context);

                yield new SwarmStepEnd(
                    id: SwarmStreamEvent::newId(),
                    runId: $state->context->runId,
                    stepIndex: $index,
                    agentClass: $agent::class,
                    agent: $agentName,
                    output: $stepOutput,
                    durationMs: $durationMs,
                    metadata: [
                        'usage' => $stepUsage,
                    ],
                    timestamp: SwarmStreamEvent::timestamp(),
                );
            }

            $state->context->mergeMetadata([
                'usage' => $mergedUsage,
            ]);
        } finally {
            // One warning per run for any best-effort checkpoint-write failures
            // (also fires if the generator was abandoned mid-stream). Those steps
            // simply re-execute on resume; the run itself succeeded.
            if ($checkpointWriteFailures !== []) {
                $this->logger->warning(
                    sprintf(
                        'laravel-swarm: %d stream step checkpoint(s) failed to persist; those steps will re-execute on resume.',
                        count($checkpointWriteFailures),
                    ),
                    [
                        'run_id' => $state->context->runId,
                        'failed_steps' => $checkpointWriteFailures,
                        'exception_class' => $firstCheckpointFailureClass,
                    ],
                );
            }

            ActiveRunContext::exit();
        }
    }

    public function runSingleStep(SwarmExecutionState $state, int $index): SwarmStep
    {
        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);
        $agent = $agents[$index] ?? null;

        if ($agent === null) {
            throw new SwarmTimeoutException("No sequential swarm agent exists at step index [{$index}].");
        }

        if (hrtime(true) >= $state->deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout while running sequentially.');
        }

        $input = $state->context->prompt();
        $this->steps->started($state, $index, $agent::class, $input);
        $snapshot = $this->snapshots->snapshot(
            $state->context->runId,
            $index,
            $this->view->present($state->swarm, $state->context, $agent),
        );

        $startedAt = MonotonicTime::now();
        ActiveRunContext::enter($state->context->runId, $state->swarm::class, $state->context);

        try {
            $response = $agent->prompt($input);
        } finally {
            ActiveRunContext::exit();
        }
        $output = (string) $response;
        $usage = $this->usageFromResponse($response);
        $this->appendResponseToolCalls($snapshot, $response);
        $mergedUsage = $this->mergeUsage(
            is_array($state->context->metadata['usage'] ?? null) ? $state->context->metadata['usage'] : [],
            $usage,
        );

        $this->guardrails->validateStep(
            $state->swarm,
            GuardrailStepContext::fromState($state, $index, $agent::class, $input, $output, []),
            $state->context,
        );

        return $this->steps->completed(
            state: $state,
            index: $index,
            agentClass: $agent::class,
            input: $input,
            output: $output,
            usage: $usage,
            durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
            contextUsage: $mergedUsage,
        );
    }

    /**
     * @param  array<int|string, mixed>|null  $summary
     * @return array<int|string, mixed>|null
     */
    protected function captureReasoningSummary(?array $summary, ?RunContext $context = null): ?array
    {
        $decision = $this->capture->outputsDecision($context);

        if ($summary === null || $decision === CaptureDecision::Full || $decision === CaptureDecision::Skip) {
            return $decision === CaptureDecision::Skip ? null : $summary;
        }

        /** @var array<int, string> $redacted */
        $redacted = $this->redactArrayPreservingKeys($summary);

        return $redacted;
    }

    protected function captureToolCall(ToolCallData $toolCall, ?RunContext $context = null): ToolCallData
    {
        $decision = $this->capture->outputsDecision($context);

        if ($decision === CaptureDecision::Full) {
            return $toolCall;
        }

        if ($decision === CaptureDecision::Skip) {
            return new ToolCallData(
                id: $toolCall->id,
                name: $toolCall->name,
                arguments: [],
                resultId: $toolCall->resultId,
                reasoningId: $toolCall->reasoningId,
                reasoningSummary: null,
            );
        }

        return new ToolCallData(
            id: $toolCall->id,
            name: $toolCall->name,
            arguments: $this->redactArrayPreservingKeys($toolCall->arguments),
            resultId: $toolCall->resultId,
            reasoningId: $toolCall->reasoningId,
            reasoningSummary: $toolCall->reasoningSummary === null
                ? null
                : $this->redactArrayPreservingKeys($toolCall->reasoningSummary),
        );
    }

    protected function captureToolResult(ToolResultData $toolResult, ?RunContext $context = null): ToolResultData
    {
        $decision = $this->capture->outputsDecision($context);

        if ($decision === CaptureDecision::Full) {
            return $toolResult;
        }

        if ($decision === CaptureDecision::Skip) {
            return new ToolResultData(
                id: $toolResult->id,
                name: $toolResult->name,
                arguments: [],
                result: null,
                resultId: $toolResult->resultId,
            );
        }

        return new ToolResultData(
            id: $toolResult->id,
            name: $toolResult->name,
            arguments: $this->redactArrayPreservingKeys($toolResult->arguments),
            result: $this->redactValue($toolResult->result),
            resultId: $toolResult->resultId,
        );
    }

    protected function captureToolError(?string $error, ?RunContext $context = null): ?string
    {
        $decision = $this->capture->outputsDecision($context);

        if ($error === null || $decision === CaptureDecision::Full) {
            return $error;
        }

        if ($decision === CaptureDecision::Skip) {
            return null;
        }

        return '[redacted]';
    }

    /**
     * @return array<string, mixed>
     */
    protected function captureProviderErrorMetadata(ProviderStreamError $event): array
    {
        $metadata = [
            'provider_error_type' => $event->type,
        ];

        if (is_array($event->metadata)) {
            $metadata['provider_metadata'] = $event->metadata;
        }

        if ($this->capture->capturesFailures()) {
            return $metadata;
        }

        return $this->redactArrayPreservingKeys($metadata);
    }

    protected function syncInvocationId(SwarmStreamEvent $swarmEvent, ?string $invocationId): void
    {
        if (is_string($invocationId)) {
            $swarmEvent->withInvocationId($invocationId);
        }
    }

    protected function appendResponseToolCalls(MemorySnapshot $snapshot, mixed $response): MemorySnapshot
    {
        foreach (SnapshotToolCallNormalizer::fromResponse($response) as $toolCall) {
            $snapshot = $this->snapshots->appendToolCall($snapshot, $toolCall);
        }

        return $snapshot;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    protected function redactArrayPreservingKeys(array $value): array
    {
        $redacted = [];

        foreach ($value as $key => $item) {
            $redacted[$key] = $this->redactValue($item);
        }

        return $redacted;
    }

    protected function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->redactArrayPreservingKeys($value);
        }

        return '[redacted]';
    }
}
