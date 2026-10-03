<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalSealBarrier;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalVoidEdge;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCitation;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeChildrenDecided;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeClosed;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeOpened;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolAttemptInvalidated;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Streaming\Protocols\SwarmProtocolEvent;
use Closure;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Throwable;

/** @internal Converts Swarm events to Laravel AI events without encoding either protocol. */
final class NativeChatProtocolAdapter
{
    /**
     * @param  Closure(string, string, NativeProtocolProjection, string):void|null  $onFailure
     */
    public function adapt(
        StreamableSwarmResponse $source,
        NativeProtocolProjection $projection,
        string $protocol = 'unknown',
        ?Closure $onFailure = null,
    ): StreamableAgentResponse {
        $response = null;
        $response = new StreamableAgentResponse(
            invocationId: $source->runId,
            generator: function () use ($source, $projection, $protocol, $onFailure, &$response): \Generator {
                if ($projection === NativeProtocolProjection::Workflow) {
                    yield from $this->workflow($source, $protocol, $onFailure);

                    return;
                }

                yield from $this->finalAgent($source, $response, $protocol, $onFailure);
            },
            meta: new Meta,
        );

        return $response;
    }

    /** @return \Generator<int, StreamEvent> */
    private function workflow(StreamableSwarmResponse $source, string $protocol, ?Closure $onFailure): \Generator
    {
        $ended = false;
        $errored = false;
        $steps = 0;

        try {
            foreach ($source as $event) {
                if ($ended || $errored) {
                    continue;
                }

                if ($event instanceof SwarmStepStart) {
                    $steps++;
                }

                if ($event instanceof SwarmStreamError) {
                    yield $this->custom($event, $this->workflowPayload($event));
                    yield $this->error($event);
                    $errored = true;

                    continue;
                }

                if ($event instanceof SwarmStreamEnd) {
                    $usage = $this->usage($event, $steps);
                    yield $this->custom($event, $this->workflowPayload($event));
                    yield $this->withInvocation(new StreamEnd(
                        id: $event->id,
                        reason: 'stop',
                        usage: $usage,
                        timestamp: $event->timestamp,
                    ), $event->invocationId);
                    $ended = true;

                    continue;
                }

                if (($payload = $this->workflowPayload($event)) !== null) {
                    yield $this->custom($event, $payload);
                }
            }
        } catch (Throwable $exception) {
            if (! $errored) {
                $reason = $this->failureReason($exception);
                $this->reportFailure($onFailure, $source, NativeProtocolProjection::Workflow, $protocol, $reason);
                yield $this->failureProgress($source, NativeProtocolProjection::Workflow, $reason);
                yield new Error(
                    id: SwarmStreamEvent::newId(),
                    type: $reason,
                    message: 'The swarm stream failed.',
                    recoverable: false,
                    timestamp: SwarmStreamEvent::timestamp(),
                );
            }

            throw $exception;
        }

        if (! $ended && ! $errored) {
            $this->reportFailure($onFailure, $source, NativeProtocolProjection::Workflow, $protocol, 'swarm_stream_incomplete');
            yield $this->failureProgress($source, NativeProtocolProjection::Workflow, 'swarm_stream_incomplete');
            yield new Error(
                id: SwarmStreamEvent::newId(),
                type: 'swarm_stream_incomplete',
                message: 'The swarm stream ended without a terminal completion event.',
                recoverable: false,
                timestamp: SwarmStreamEvent::timestamp(),
            );
        }
    }

    /** @return \Generator<int, StreamEvent> */
    private function finalAgent(
        StreamableSwarmResponse $source,
        StreamableAgentResponse $response,
        string $protocol,
        ?Closure $onFailure,
    ): \Generator {
        $current = [];
        $last = [];
        $lastNativeResult = null;
        $steps = 0;
        $ended = false;
        $errored = false;
        $openStepIndex = null;

        try {
            foreach ($source as $event) {
                if ($ended || $errored) {
                    continue;
                }

                if ($event instanceof SwarmStreamStart) {
                    yield $this->custom($event, $this->finalAgentProgressPayload($event, 'buffering'));

                    continue;
                }

                if ($event instanceof SwarmStepStart) {
                    $steps++;
                    $current = [];
                    $openStepIndex = $event->stepIndex;

                    yield $this->custom($event, $this->finalAgentProgressPayload($event, 'step_started'));

                    continue;
                }

                if ($event instanceof SwarmStepEnd) {
                    if ($openStepIndex !== $event->stepIndex) {
                        continue;
                    }

                    $last = $current;
                    $lastNativeResult = $event->nativeResult;
                    $openStepIndex = null;

                    yield $this->custom($event, $this->finalAgentProgressPayload($event, 'step_completed'));

                    continue;
                }

                if ($event instanceof SwarmStreamError) {
                    yield $this->custom($event, $this->workflowPayload($event));
                    yield $this->error($event);
                    $errored = true;

                    continue;
                }

                if ($event instanceof SwarmStreamEnd) {
                    if ($openStepIndex !== null) {
                        $reason = 'swarm_stream_incomplete_step';
                        $this->reportFailure($onFailure, $source, NativeProtocolProjection::FinalAgent, $protocol, $reason);
                        yield $this->failureProgress($source, NativeProtocolProjection::FinalAgent, $reason, $event);
                        yield new Error(
                            id: $event->id,
                            type: $reason,
                            message: 'The swarm stream ended before its final step completed.',
                            recoverable: false,
                            timestamp: $event->timestamp,
                        );
                        $errored = true;

                        continue;
                    }

                    $usage = $this->usage($event, $steps);
                    $this->adoptNativeMessageRows($response, $lastNativeResult);

                    yield $this->custom($event, [
                        ...$this->identity($event),
                        'event_type' => 'projection',
                        'projection' => NativeProtocolProjection::FinalAgent->value,
                    ]);
                    yield from $this->finalAgentEvents($last);
                    yield $this->withInvocation(new StreamEnd(
                        id: $event->id,
                        reason: 'stop',
                        usage: $usage,
                        timestamp: $event->timestamp,
                    ), $event->invocationId);
                    $ended = true;

                    continue;
                }

                if ($this->isFinalAgentCandidate($event)) {
                    $current[] = $event;
                }
            }
        } catch (Throwable $exception) {
            if (! $errored) {
                $reason = $this->failureReason($exception);
                $this->reportFailure($onFailure, $source, NativeProtocolProjection::FinalAgent, $protocol, $reason);
                yield $this->failureProgress($source, NativeProtocolProjection::FinalAgent, $reason);
                yield new Error(
                    id: SwarmStreamEvent::newId(),
                    type: $reason,
                    message: 'The swarm stream failed.',
                    recoverable: false,
                    timestamp: SwarmStreamEvent::timestamp(),
                );
            }

            throw $exception;
        }

        if (! $ended && ! $errored) {
            $this->reportFailure($onFailure, $source, NativeProtocolProjection::FinalAgent, $protocol, 'swarm_stream_incomplete');
            yield $this->failureProgress($source, NativeProtocolProjection::FinalAgent, 'swarm_stream_incomplete');
            yield new Error(
                id: SwarmStreamEvent::newId(),
                type: 'swarm_stream_incomplete',
                message: 'The swarm stream ended without a terminal completion event.',
                recoverable: false,
                timestamp: SwarmStreamEvent::timestamp(),
            );
        }
    }

    /**
     * @param  array<int, SwarmStreamEvent>  $events
     * @return \Generator<int, StreamEvent>
     */
    private function finalAgentEvents(array $events): \Generator
    {
        $openMessages = [];
        $suppressedMessages = [];

        foreach ($events as $event) {
            if ($event instanceof SwarmTextDelta) {
                if ($event->payloadAvailability !== PayloadAvailability::Available) {
                    $key = $event->messageId ?? $event->id;

                    if (! isset($suppressedMessages[$key])) {
                        $suppressedMessages[$key] = true;
                        yield $this->custom($event, $this->workflowPayload($event));
                    }

                    continue;
                }

                $messageId = $this->requiredMessageId($event);

                if (! isset($openMessages[$messageId])) {
                    $openMessages[$messageId] = true;
                    yield $this->withInvocation(new TextStart($event->id.'-start', $messageId, $event->timestamp), $event->invocationId);
                }

                if ($event->delta !== null) {
                    yield $this->withInvocation(new TextDelta($event->id, $messageId, $event->delta, $event->timestamp), $event->invocationId);
                }

                continue;
            }

            if ($event instanceof SwarmTextEnd) {
                if ($event->payloadAvailability !== PayloadAvailability::Available || isset($suppressedMessages[$event->messageId])) {
                    if (! isset($suppressedMessages[$event->messageId])) {
                        $suppressedMessages[$event->messageId] = true;
                        yield $this->custom($event, $this->workflowPayload($event));
                    }

                    continue;
                }

                if (! isset($openMessages[$event->messageId])) {
                    $openMessages[$event->messageId] = true;
                    yield $this->withInvocation(new TextStart($event->id.'-start', $event->messageId, $event->timestamp), $event->invocationId);
                }

                unset($openMessages[$event->messageId]);
                yield $this->withInvocation(new TextEnd($event->id, $event->messageId, $event->timestamp), $event->invocationId);

                continue;
            }

            if ($event instanceof SwarmToolCall || $event instanceof SwarmToolResult) {
                if ($event->payloadAvailability === PayloadAvailability::Available) {
                    yield $event instanceof SwarmToolCall
                        ? $this->withInvocation(new ToolCall($event->id, $event->toolCall, $event->timestamp), $event->invocationId)
                        : $this->withInvocation(new ToolResult(
                            $event->id,
                            $event->toolResult,
                            $event->successful,
                            $event->error,
                            $event->timestamp,
                            $event->denied,
                            $event->preliminary,
                        ), $event->invocationId);
                } else {
                    yield $this->custom($event, $this->workflowPayload($event));
                }

                continue;
            }

            if ($event instanceof SwarmProviderToolEvent) {
                if ($event->payload->status === 'available' && $event->provider !== null) {
                    yield $this->withInvocation(new ProviderToolEvent(
                        id: $event->id,
                        itemId: $event->itemId,
                        type: $event->providerType,
                        data: $event->payload->data,
                        status: $event->providerStatus,
                        timestamp: $event->timestamp,
                        provider: $event->provider,
                    ), $event->invocationId);
                } else {
                    yield $this->custom($event, $this->workflowPayload($event));
                }

                continue;
            }

            if ($event instanceof SwarmCitation) {
                if ($event->citationEvidence->status !== 'available') {
                    yield $this->custom($event, $this->workflowPayload($event));

                    continue;
                }

                foreach ($event->citationEvidence->items as $citation) {
                    $messageId = $citation->messageId ?? $event->messageId;

                    if ($messageId === null) {
                        yield $this->custom($event, $this->workflowPayload($event));

                        continue;
                    }

                    yield $this->withInvocation(new Citation(
                        id: $citation->eventId ?? $event->id,
                        messageId: $messageId,
                        citation: new UrlCitation($citation->url, $citation->title, $citation->startIndex, $citation->endIndex),
                        timestamp: $citation->timestamp ?? $event->timestamp,
                    ), $citation->invocationId ?? $event->invocationId);
                }
            }
        }

        foreach (array_keys($openMessages) as $messageId) {
            yield new TextEnd(SwarmStreamEvent::newId(), $messageId, SwarmStreamEvent::timestamp());
        }
    }

    private function isFinalAgentCandidate(SwarmStreamEvent $event): bool
    {
        return $event instanceof SwarmTextDelta
            || $event instanceof SwarmTextEnd
            || $event instanceof SwarmToolCall
            || $event instanceof SwarmToolResult
            || $event instanceof SwarmProviderToolEvent
            || $event instanceof SwarmCitation
            || $event instanceof SwarmReasoningDelta
            || $event instanceof SwarmReasoningEnd;
    }

    /** @return array<string, mixed>|null */
    private function workflowPayload(SwarmStreamEvent $event): ?array
    {
        $specific = match (true) {
            $event instanceof SwarmStreamStart => [
                'event_type' => 'workflow_started',
                'topology' => $event->topology,
            ],
            $event instanceof SwarmStepStart => [
                'event_type' => 'step_started',
                'step_index' => $event->stepIndex,
            ],
            $event instanceof SwarmTextDelta => [
                'event_type' => 'text_delta',
                'step_index' => $event->stepIndex,
                'message_id' => $event->messageId,
                'content_status' => $event->payloadAvailability->value,
                ...($event->payloadAvailability === PayloadAvailability::Available && $event->delta !== null ? ['delta' => $event->delta] : []),
            ],
            $event instanceof SwarmTextEnd => [
                'event_type' => 'text_ended',
                'step_index' => $event->stepIndex,
                'message_id' => $event->messageId,
                'content_status' => $event->payloadAvailability->value,
            ],
            $event instanceof SwarmReasoningDelta, $event instanceof SwarmReasoningEnd => [
                'event_type' => 'reasoning_withheld',
                'step_index' => $event->stepIndex,
                'content_status' => 'omitted',
            ],
            $event instanceof SwarmToolCall => [
                'event_type' => 'tool_called',
                'step_index' => $event->stepIndex,
                'tool_call_id' => $event->toolCall->id,
                'tool_name' => $event->toolCall->name,
                'payload_status' => $event->payloadAvailability->value,
                ...($this->mayExposePayload($event->payloadAvailability) ? ['arguments' => $event->toolCall->arguments] : []),
            ],
            $event instanceof SwarmToolResult => [
                'event_type' => $event->preliminary ? 'tool_result_preliminary' : 'tool_result',
                'step_index' => $event->stepIndex,
                'tool_call_id' => $event->toolResult->id,
                'tool_name' => $event->toolResult->name,
                'successful' => $event->successful,
                'denied' => $event->denied,
                'payload_status' => $event->payloadAvailability->value,
                ...($this->mayExposePayload($event->payloadAvailability) ? ['result' => $event->toolResult->result] : []),
                ...($event->error === null ? [] : ['error' => $event->error]),
            ],
            $event instanceof SwarmProviderToolEvent => [
                'event_type' => 'provider_tool',
                'step_index' => $event->stepIndex,
                'provider' => $event->provider,
                'provider_item_id' => $event->itemId,
                'provider_type' => $event->providerType,
                'provider_status' => $event->providerStatus,
                'payload_status' => $event->payload->status,
                'payload_reasons' => $event->payload->reasons,
                ...($event->payload->status === 'available' ? ['data' => $event->payload->data] : []),
            ],
            $event instanceof SwarmCitation => [
                'event_type' => 'citation',
                'step_index' => $event->stepIndex,
                'message_id' => $event->messageId,
                'citation_status' => $event->citationEvidence->status,
                'citation_reasons' => $event->citationEvidence->reasons,
                'citations' => array_map(static fn ($citation): array => [
                    'url' => $citation->url,
                    'title' => $citation->title,
                    'start_index' => $citation->startIndex,
                    'end_index' => $citation->endIndex,
                    'message_id' => $citation->messageId,
                ], $event->citationEvidence->items),
            ],
            $event instanceof SwarmStepEnd => [
                'event_type' => 'step_completed',
                'step_index' => $event->stepIndex,
                'native_result_status' => $this->nativeResultStatus($event->nativeResult),
            ],
            $event instanceof SwarmStreamEnd => [
                'event_type' => 'workflow_completed',
                'output_status' => $event->output === null ? 'omitted' : 'available',
                ...($event->output === null ? [] : ['output' => $event->output]),
            ],
            $event instanceof SwarmStreamError => [
                'event_type' => 'workflow_error',
                'message' => $event->message,
                'recoverable' => $event->recoverable,
            ],
            $event instanceof SwarmNodeOpened => [
                'event_type' => 'node_opened',
                'parent_node_id' => $event->parentNodeId,
                'role' => $event->role,
            ],
            $event instanceof SwarmNodeChildrenDecided => [
                'event_type' => 'node_children_decided',
                'child_node_ids' => $event->childNodeIds,
            ],
            $event instanceof SwarmNodeClosed => ['event_type' => 'node_closed'],
            $event instanceof SwarmCausalVoidEdge => [
                'event_type' => 'causal_event_voided',
                'void_type' => $event->voidType->value,
                'target_event_id' => $event->targetEventId,
                'digest_node_id' => $event->digestNodeId,
            ],
            $event instanceof SwarmProviderToolAttemptInvalidated => [
                'event_type' => 'provider_tool_attempt_invalidated',
                'before_epoch' => $event->beforeEpoch,
            ],
            $event instanceof SwarmCausalSealBarrier => ['event_type' => 'causal_log_sealed'],
            default => null,
        };

        return $specific === null ? null : array_filter(
            [...$this->identity($event), ...$specific],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /** @return array<string, string|int|null> */
    private function identity(SwarmStreamEvent $event): array
    {
        return [
            'event_id' => property_exists($event, 'id') && is_string($event->id) ? $event->id : null,
            'run_id' => property_exists($event, 'runId') && is_string($event->runId) ? $event->runId : null,
            'invocation_id' => $event->invocationId,
            'node_id' => $event->nodeId,
            'branch_id' => $event->branchId,
            'attempt_id' => $event->attemptId,
            'branch_sequence' => $event->branchSequence,
            'attempt_epoch' => $event->attemptEpoch,
        ];
    }

    private function usage(SwarmStreamEnd $event, int $steps): TextUsage
    {
        if ($steps === 0 && $event->usage === []) {
            return new TextUsage;
        }

        $input = $event->usage['input_tokens'] ?? null;
        $output = $event->usage['output_tokens'] ?? null;

        if (! is_int($input) || $input < 0 || ! is_int($output) || $output < 0) {
            throw new NativeProtocolProjectionException(
                'swarm_usage_unavailable',
                'Native protocol projection requires exact aggregate input_tokens and output_tokens for a non-empty run.',
            );
        }

        return new TextUsage(
            inputTokens: $input,
            outputTokens: $output,
            cacheReadInputTokens: $this->optionalUsage($event, 'cache_read_input_tokens'),
            cacheWriteInputTokens: $this->optionalUsage($event, 'cache_write_input_tokens'),
            reasoningTokens: $this->optionalUsage($event, 'reasoning_tokens'),
        );
    }

    private function optionalUsage(SwarmStreamEnd $event, string $key): ?int
    {
        $value = $event->usage[$key] ?? null;

        if ($value !== null && (! is_int($value) || $value < 0)) {
            throw new NativeProtocolProjectionException(
                'swarm_usage_invalid',
                "Native protocol projection received invalid aggregate usage [{$key}].",
            );
        }

        return $value;
    }

    private function requiredMessageId(SwarmTextDelta $event): string
    {
        if ($event->messageId === null || $event->messageId === '') {
            throw new NativeProtocolProjectionException(
                'swarm_message_identity_unavailable',
                'Native protocol projection cannot fabricate a missing streamed content-block message ID.',
            );
        }

        return $event->messageId;
    }

    private function mayExposePayload(PayloadAvailability $availability): bool
    {
        return $availability === PayloadAvailability::Available;
    }

    /** @return array<string, mixed> */
    private function finalAgentProgressPayload(SwarmStreamEvent $event, string $state): array
    {
        return array_filter([
            ...$this->identity($event),
            'event_type' => 'projection_progress',
            'projection' => NativeProtocolProjection::FinalAgent->value,
            'state' => $state,
            'step_index' => property_exists($event, 'stepIndex') && is_int($event->stepIndex) ? $event->stepIndex : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function failureReason(Throwable $exception): string
    {
        return $exception instanceof NativeProtocolProjectionException
            ? $exception->reason
            : 'swarm_stream_failed';
    }

    /**
     * @param  Closure(string, string, NativeProtocolProjection, string):void|null  $onFailure
     */
    private function reportFailure(
        ?Closure $onFailure,
        StreamableSwarmResponse $source,
        NativeProtocolProjection $projection,
        string $protocol,
        string $reason,
    ): void {
        try {
            $onFailure?->__invoke($source->runId, $protocol, $projection, $reason);
        } catch (Throwable) {
            // Operational diagnostics must never replace the projection failure
            // or suppress the terminal protocol error sent to the client.
        }
    }

    private function failureProgress(
        StreamableSwarmResponse $source,
        NativeProtocolProjection $projection,
        string $reason,
        ?SwarmStreamEvent $event = null,
    ): SwarmProtocolEvent {
        $failure = new SwarmProtocolEvent(
            id: $event !== null && property_exists($event, 'id') && is_string($event->id)
                ? $event->id.'-projection-error'
                : SwarmStreamEvent::newId(),
            payload: array_filter([
                'event_type' => 'projection_error',
                'run_id' => $source->runId,
                'projection' => $projection->value,
                'reason' => $reason,
            ]),
            timestamp: $event !== null && property_exists($event, 'timestamp') && is_int($event->timestamp)
                ? $event->timestamp
                : SwarmStreamEvent::timestamp(),
        );

        return $event === null ? $failure : $this->withInvocation($failure, $event->invocationId);
    }

    private function adoptNativeMessageRows(StreamableAgentResponse $response, ?NativeStepResult $native): void
    {
        if ($native === null) {
            return;
        }

        $response->conversationId = $native->conversationId;
        $response->userMessageId = $native->userMessageId;
        $response->assistantMessageId = $native->assistantMessageId;
    }

    private function nativeResultStatus(?NativeStepResult $native): string
    {
        if ($native === null) {
            return NativeStepResult::UNAVAILABLE;
        }

        return $native->status;
    }

    /** @param array<string, mixed> $payload */
    private function custom(SwarmStreamEvent $source, array $payload): SwarmProtocolEvent
    {
        return $this->withInvocation(new SwarmProtocolEvent(
            id: property_exists($source, 'id') && is_string($source->id) ? $source->id : SwarmStreamEvent::newId(),
            payload: $payload,
            timestamp: property_exists($source, 'timestamp') && is_int($source->timestamp)
                ? $source->timestamp
                : SwarmStreamEvent::timestamp(),
        ), $source->invocationId);
    }

    private function error(SwarmStreamError $event): Error
    {
        return $this->withInvocation(new Error(
            id: $event->id,
            type: 'swarm_stream_error',
            message: $event->message ?? 'The swarm stream failed.',
            recoverable: $event->recoverable,
            timestamp: $event->timestamp,
        ), $event->invocationId);
    }

    /** @template T of \Laravel\Ai\Streaming\Events\StreamEvent
     * @param  T  $event
     * @return T
     */
    private function withInvocation(StreamEvent $event, ?string $invocationId): StreamEvent
    {
        return $invocationId === null ? $event : $event->withInvocationId($invocationId);
    }
}
