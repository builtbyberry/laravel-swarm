<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Concerns\MergesAgentUsage;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Generator;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

class SequentialRunner
{
    use MergesAgentUsage;

    public function __construct(
        protected SwarmStepRecorder $steps,
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
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

        foreach ($agents as $index => $agent) {
            if (hrtime(true) >= $state->deadlineMonotonic) {
                throw new SwarmTimeoutException('The swarm exceeded its configured timeout while streaming sequentially.');
            }

            $input = $state->context->prompt();
            $agentName = class_basename($agent::class);

            $this->steps->started($state, $index, $agent::class, $input);

            yield new SwarmStepStart(
                id: SwarmStreamEvent::newId(),
                runId: $state->context->runId,
                stepIndex: $index,
                agentClass: $agent::class,
                agent: $agentName,
                input: $this->capture->input($input),
                timestamp: SwarmStreamEvent::timestamp(),
            );

            $startedAt = MonotonicTime::now();
            $durationMs = null;
            $stepUsage = [];

            if ($index === $lastIndex) {
                $stream = $agent->stream($input);
                $output = '';

                foreach ($stream as $event) {
                    if ($event instanceof TextDelta) {
                        $output .= $event->delta;
                        $swarmEvent = new SwarmTextDelta(
                            id: $event->id,
                            runId: $state->context->runId,
                            stepIndex: $index,
                            agentClass: $agent::class,
                            delta: $this->capture->output($event->delta),
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
                            delta: $this->capture->output($event->delta),
                            timestamp: $event->timestamp,
                            summary: $this->captureReasoningSummary($event->summary),
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
                            summary: $this->captureReasoningSummary($event->summary),
                        );
                        $this->syncInvocationId($swarmEvent, $event->invocationId);

                        yield $swarmEvent;
                    } elseif ($event instanceof ToolCall) {
                        $swarmEvent = new SwarmToolCall(
                            id: $event->id,
                            runId: $state->context->runId,
                            stepIndex: $index,
                            agentClass: $agent::class,
                            toolCall: $this->captureToolCall($event->toolCall),
                            timestamp: $event->timestamp,
                        );
                        $this->syncInvocationId($swarmEvent, $event->invocationId);

                        yield $swarmEvent;
                    } elseif ($event instanceof ToolResult) {
                        $swarmEvent = new SwarmToolResult(
                            id: $event->id,
                            runId: $state->context->runId,
                            stepIndex: $index,
                            agentClass: $agent::class,
                            toolResult: $this->captureToolResult($event->toolResult),
                            successful: $event->successful,
                            error: $this->captureToolError($event->error),
                            timestamp: $event->timestamp,
                        );
                        $this->syncInvocationId($swarmEvent, $event->invocationId);

                        yield $swarmEvent;
                    } elseif ($event instanceof StreamEnd) {
                        $stepUsage = $event->usage->toArray();
                    }
                }

                $durationMs = MonotonicTime::elapsedMilliseconds($startedAt);
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
                $response = $agent->prompt($input);
                $output = (string) $response;
                $stepUsage = $this->usageFromResponse($response);

                $step = $this->steps->completed(
                    state: $state,
                    index: $index,
                    agentClass: $agent::class,
                    input: $input,
                    output: $output,
                    usage: $stepUsage,
                    durationMs: $durationMs = MonotonicTime::elapsedMilliseconds($startedAt),
                );
            }

            $mergedUsage = $this->mergeUsage($mergedUsage, $stepUsage);
            $stepOutput = $step->artifacts[0]->content ?? $this->capture->output($output);

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

        $startedAt = MonotonicTime::now();
        $response = $agent->prompt($input);
        $output = (string) $response;
        $usage = $this->usageFromResponse($response);
        $mergedUsage = $this->mergeUsage(
            is_array($state->context->metadata['usage'] ?? null) ? $state->context->metadata['usage'] : [],
            $usage,
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

    protected function captureReasoningSummary(?array $summary): ?array
    {
        if ($summary === null || $this->capture->capturesOutputs()) {
            return $summary;
        }

        /** @var array<int, string> $redacted */
        $redacted = $this->redactArrayPreservingKeys($summary);

        return $redacted;
    }

    protected function captureToolCall(ToolCallData $toolCall): ToolCallData
    {
        if ($this->capture->capturesOutputs()) {
            return $toolCall;
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

    protected function captureToolResult(ToolResultData $toolResult): ToolResultData
    {
        if ($this->capture->capturesOutputs()) {
            return $toolResult;
        }

        return new ToolResultData(
            id: $toolResult->id,
            name: $toolResult->name,
            arguments: $this->redactArrayPreservingKeys($toolResult->arguments),
            result: $this->redactValue($toolResult->result),
            resultId: $toolResult->resultId,
        );
    }

    protected function captureToolError(?string $error): ?string
    {
        if ($error === null || $this->capture->capturesOutputs()) {
            return $error;
        }

        return '[redacted]';
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
