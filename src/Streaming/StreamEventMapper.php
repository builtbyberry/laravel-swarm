<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmStreamProviderException;
use BuiltByBerry\LaravelSwarm\Memory\SnapshotToolCallNormalizer;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableNodeStreamRecorder;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Laravel\Ai\Contracts\Agent;
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

/**
 * The single vendor → swarm stream-event fold, shared by every runner that maps a
 * provider stream into swarm events: the live {@see SequentialRunner::stream()}
 * loop, the durable per-node stream ({@see SequentialRunner::streamSingleStep()}),
 * and — from #311/#312 — the durable hierarchical and parallel-branch advancers.
 *
 * One copy is what keeps the folds from drifting (the #288 enumerate-every-emit-site
 * lesson, lifted one level up so a new provider event type is mapped identically
 * across every topology rather than handled in one runner and dropped in another).
 *
 * The mapper is deliberately **identity-agnostic**: it never stamps a node id or
 * attempt epoch on the events it produces. Per-node / per-branch identity is the
 * sink's job ({@see DurableNodeStreamRecorder::sinkFor()}),
 * so the durable parallel-branch path can stamp `node_id ?? branch_id` and
 * `epoch = attempts` without forking this fold.
 *
 * It holds no per-run state — only the shared capture/snapshots collaborators — and
 * mutates only the caller-owned {@see StreamStepAccumulator} passed to {@see map()},
 * so two concurrent runs in one Octane worker never share a step's buffer.
 *
 * @internal
 */
class StreamEventMapper
{
    public function __construct(
        protected SwarmCapture $capture,
        protected SnapshotsMemory $snapshots,
    ) {}

    /**
     * Map one provider stream event to its swarm event, folding the per-step side
     * effects (output text, tool-call pairing, usage, unrecognized-class
     * breadcrumb) into `$accumulator`. Returns the swarm event to emit, or null for
     * a lifecycle event ({@see StreamEnd} records usage) or an unrecognized event
     * (recorded as a breadcrumb class on the accumulator, never thrown). Throws
     * {@see SwarmStreamProviderException} on a provider error event.
     */
    public function map(
        mixed $event,
        SwarmExecutionState $state,
        int $index,
        Agent $agent,
        StreamStepAccumulator $accumulator,
    ): ?SwarmStreamEvent {
        if ($event instanceof TextDelta) {
            $accumulator->output .= $event->delta;
            $swarmEvent = new SwarmTextDelta(
                id: $event->id,
                runId: $state->context->runId,
                stepIndex: $index,
                agentClass: $agent::class,
                delta: $this->capture->applyOutput($event->delta, $state->context),
                timestamp: $event->timestamp,
            );
            $this->syncInvocationId($swarmEvent, $event->invocationId);

            return $swarmEvent;
        }

        if ($event instanceof TextEnd) {
            $swarmEvent = new SwarmTextEnd(
                id: $event->id,
                runId: $state->context->runId,
                stepIndex: $index,
                agentClass: $agent::class,
                messageId: $event->messageId,
                timestamp: $event->timestamp,
            );
            $this->syncInvocationId($swarmEvent, $event->invocationId);

            return $swarmEvent;
        }

        if ($event instanceof ReasoningDelta) {
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

            return $swarmEvent;
        }

        if ($event instanceof ReasoningEnd) {
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

            return $swarmEvent;
        }

        if ($event instanceof ToolCall) {
            // Hold the call until we see its matching ToolResult so the snapshot
            // row records a paired input/output entry, then a single appendToolCall
            // persists the finalized pair. This keeps the snapshot row write count
            // proportional to tool results, not events.
            $accumulator->pendingToolCalls[$event->toolCall->id] = $event->toolCall;

            $swarmEvent = new SwarmToolCall(
                id: $event->id,
                runId: $state->context->runId,
                stepIndex: $index,
                agentClass: $agent::class,
                toolCall: $this->captureToolCall($event->toolCall, $state->context),
                timestamp: $event->timestamp,
            );
            $this->syncInvocationId($swarmEvent, $event->invocationId);

            return $swarmEvent;
        }

        if ($event instanceof ToolResult) {
            $matchedCallId = $event->toolResult->id;
            $matchedCall = $accumulator->pendingToolCalls[$matchedCallId] ?? null;

            if ($matchedCall !== null) {
                unset($accumulator->pendingToolCalls[$matchedCallId]);
                $accumulator->snapshot = $this->snapshots->appendToolCall(
                    $accumulator->snapshot,
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

            return $swarmEvent;
        }

        if ($event instanceof StreamEnd) {
            $accumulator->stepUsage = $event->usage->toArray();

            return null;
        }

        if ($event instanceof ProviderStreamError) {
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

        // An event type this chain does not map. Record its type (never its
        // payload) so the silent snapshot drop becomes a visible breadcrumb; never
        // throw — a harmless new provider event must not abort an otherwise-
        // successful run. get_debug_type, not ::class: the branch exists to catch
        // contract violations, so it must not itself fatal on a non-object event.
        $accumulator->unknownEventClasses[get_debug_type($event)] = true;

        return null;
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
