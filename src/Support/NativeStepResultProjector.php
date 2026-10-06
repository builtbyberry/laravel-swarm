<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\StructuredStep;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\StructuredAgentResponse;

/** Builds the safe plain-data projection; never serializes a native object. @internal */
final class NativeStepResultProjector
{
    private const MAX_PERSISTED_REASONS = 16;

    private const MAX_PERSISTED_REASON_BYTES = 64;

    /** @var list<string> */
    private const USAGE_KEYS = ['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'];

    /** @var list<string> */
    private const TOOL_STATUSES = ['pending', 'succeeded', 'denied', 'failed'];

    public function __construct(private ConfigRepository $config) {}

    public function fromResponse(AgentResponse $response): NativeStepResult
    {
        $state = new NativeStepResultProjectionState;
        $structured = $response instanceof StructuredAgentResponse
            ? $this->normalizeStructured($response->structured, $state)
            : null;

        $generationSteps = [];
        foreach ($response->steps->take($this->limit('max_generation_steps', 64)) as $step) {
            if (! $step instanceof Step) {
                $state->reason('unsupported_type');

                continue;
            }
            $generationSteps[] = $this->generationStep($step, $state);
        }
        if ($response->steps->count() > count($generationSteps)) {
            $state->reason('limit');
        }

        $tools = $this->tools($response->toolCalls->all(), $response->toolResults->all(), $state);
        $reasoning = $this->boundedString($response->reasoning, $this->limit('max_reasoning_bytes', 65536), $state);
        $result = new NativeStepResult(
            status: $state->reasons === [] ? NativeStepResult::AVAILABLE : NativeStepResult::PARTIAL,
            structured: $structured,
            reasoning: $reasoning === '' ? null : $reasoning,
            provider: $response->meta->provider,
            model: $response->meta->model,
            invocationId: $response->invocationId !== '' ? $response->invocationId : null,
            conversationId: $response->conversationId,
            userMessageId: $response->userMessageId,
            assistantMessageId: $response->assistantMessageId,
            generationSteps: $generationSteps,
            tools: array_values($tools),
            reasons: $state->reasons,
        );

        return $this->applyTotalLimit($result, $state, $this->limit('max_bytes', 262144));
    }

    public function capture(NativeStepResult $result, CaptureDecision $decision): NativeStepResult
    {
        if ($decision === CaptureDecision::Full) {
            return $result;
        }
        if ($decision === CaptureDecision::Skip) {
            return NativeStepResult::omitted();
        }

        $state = new NativeStepResultProjectionState([...$result->reasons, 'capture_redact']);
        $redacted = new NativeStepResult(
            status: NativeStepResult::REDACTED,
            provider: $result->provider,
            model: $result->model,
            invocationId: $result->invocationId,
            generationSteps: array_map(static fn (array $step): array => array_filter([
                'finish_reason' => $step['finish_reason'] ?? null,
                'provider' => $step['provider'] ?? null,
                'model' => $step['model'] ?? null,
                'usage' => is_array($step['usage'] ?? null) ? $step['usage'] : null,
            ], static fn (mixed $value): bool => $value !== null), $result->generationSteps),
            tools: array_map(static fn (array $tool): array => array_filter([
                'call_id' => $tool['call_id'] ?? null,
                'result_id' => $tool['result_id'] ?? null,
                'name' => $tool['name'] ?? null,
                'status' => $tool['status'] ?? null,
            ], static fn (mixed $value): bool => $value !== null), $result->tools),
            reasons: $state->reasons,
        );

        return $this->applyTotalLimit($redacted, $state, $this->limit('max_bytes', 262144), NativeStepResult::REDACTED);
    }

    /**
     * Revalidate plain data returned by a cache or custom persistence boundary.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fromPersistedArray(array $payload): NativeStepResult
    {
        $result = NativeStepResult::fromArray($payload);
        if ($result->status === NativeStepResult::UNAVAILABLE) {
            return NativeStepResult::unavailable($this->persistedReasons($result->reasons, ['malformed']));
        }
        if ($result->status === NativeStepResult::OMITTED) {
            return NativeStepResult::omitted();
        }

        $state = new NativeStepResultProjectionState($this->persistedReasons($result->reasons));
        $generationSteps = [];
        foreach (array_slice($result->generationSteps, 0, $this->limit('max_generation_steps', 64)) as $step) {
            $reasoning = $this->persistedString($step['reasoning'] ?? null, $state);
            if ($reasoning !== null) {
                $reasoning = $this->boundedString($reasoning, $this->limit('max_reasoning_bytes', 65536), $state);
            }
            $generationSteps[] = array_filter([
                'text' => $this->persistedString($step['text'] ?? null, $state),
                'structured' => is_array($step['structured'] ?? null) ? $this->normalizeStructured($step['structured'], $state) : null,
                'reasoning' => $reasoning,
                'finish_reason' => $this->persistedString($step['finish_reason'] ?? null, $state),
                'provider' => $this->persistedString($step['provider'] ?? null, $state),
                'model' => $this->persistedString($step['model'] ?? null, $state),
                'usage' => $this->usage($step['usage'] ?? null, $state),
            ], static fn (mixed $value): bool => $value !== null);
        }
        if (count($result->generationSteps) > count($generationSteps)) {
            $state->reason('limit');
        }

        $tools = array_map(fn (array $tool): array => array_filter([
            'call_id' => $this->persistedString($tool['call_id'] ?? null, $state),
            'result_id' => $this->persistedString($tool['result_id'] ?? null, $state),
            'name' => $this->persistedString($tool['name'] ?? null, $state),
            'status' => $this->persistedToolStatus($tool['status'] ?? null, $state),
        ], static fn (mixed $value): bool => $value !== null), array_slice($result->tools, 0, $this->limit('max_tool_statuses', 256)));
        if (count($result->tools) > count($tools)) {
            $state->reason('limit');
        }

        $structured = $result->structured === null ? null : $this->normalizeStructured($result->structured, $state);
        $reasoning = $result->reasoning === null ? null : $this->boundedString($result->reasoning, $this->limit('max_reasoning_bytes', 65536), $state);
        $normalized = new NativeStepResult(
            status: $state->reasons === $result->reasons ? $result->status : ($result->status === NativeStepResult::REDACTED ? NativeStepResult::REDACTED : NativeStepResult::PARTIAL),
            structured: $structured,
            reasoning: $reasoning,
            provider: $result->provider,
            model: $result->model,
            invocationId: $result->invocationId,
            conversationId: $result->conversationId,
            userMessageId: $result->userMessageId,
            assistantMessageId: $result->assistantMessageId,
            generationSteps: $generationSteps,
            tools: $tools,
            reasons: $state->reasons,
        );

        if ($result->status === NativeStepResult::REDACTED) {
            return $this->capture($normalized, CaptureDecision::Redact);
        }

        return $this->applyTotalLimit($normalized, $state, $this->limit('max_bytes', 262144), $result->status === NativeStepResult::REDACTED ? NativeStepResult::REDACTED : null);
    }

    /** Bound the native-result portion of broadcastable stream events. */
    public function forStreamEvent(NativeStepResult $result): NativeStepResult
    {
        $state = new NativeStepResultProjectionState($result->reasons);

        return $this->applyTotalLimit(
            $result,
            $state,
            $this->limit('max_event_bytes', 4096),
            $result->status === NativeStepResult::AVAILABLE ? NativeStepResult::PARTIAL : $result->status,
            'transport_limit',
        );
    }

    /** @return array<string, int> */
    public function resolvedLimits(): array
    {
        return [
            'max_bytes' => $this->limit('max_bytes', 262144),
            'max_event_bytes' => $this->limit('max_event_bytes', 4096),
            'max_generation_steps' => $this->limit('max_generation_steps', 64),
            'max_tool_statuses' => $this->limit('max_tool_statuses', 256),
            'max_structured_depth' => $this->limit('max_structured_depth', 32),
            'max_structured_items' => $this->limit('max_structured_items', 4096),
            'max_reasoning_bytes' => $this->limit('max_reasoning_bytes', 65536),
        ];
    }

    /** @param array<string, int> $limits */
    public static function fromResolvedLimits(array $limits): self
    {
        return new self(new Config(['swarm' => ['native_results' => $limits]]));
    }

    /** @return array<string, mixed> */
    private function generationStep(Step $step, NativeStepResultProjectionState $state): array
    {
        $structured = $step instanceof StructuredStep ? $this->normalizeStructured($step->structured, $state) : null;
        $reasoning = $this->boundedString($step->reasoning, $this->limit('max_reasoning_bytes', 65536), $state);

        return array_filter([
            'text' => $step->text,
            'structured' => $structured,
            'reasoning' => $reasoning === '' ? null : $reasoning,
            'finish_reason' => $step->finishReason->value,
            'provider' => $step->meta->provider,
            'model' => $step->meta->model,
            'usage' => $this->usage($step->usage->toArray(), $state),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<mixed>  $calls
     * @param  array<mixed>  $results
     * @return list<array<string, mixed>>
     */
    private function tools(array $calls, array $results, NativeStepResultProjectionState $state): array
    {
        $limit = $this->limit('max_tool_statuses', 256);
        $resultEntries = [];
        $resultIndexes = [];
        $usedResultIndexes = [];
        foreach (array_slice($results, 0, $limit + 1) as $result) {
            if ($result instanceof ToolResult) {
                $index = count($resultEntries);
                $resultEntries[] = $result;
                $resultIndexes[$result->id][] = $index;
            } else {
                $state->reason('unsupported_type');
            }
        }

        $projected = [];
        foreach (array_slice($calls, 0, $limit + 1) as $call) {
            if (! $call instanceof ToolCall) {
                $state->reason('unsupported_type');

                continue;
            }
            $lookupId = $call->resultId ?? $call->id;
            $result = null;
            $index = isset($resultIndexes[$lookupId]) ? array_shift($resultIndexes[$lookupId]) : null;
            if (! is_int($index) && $lookupId !== $call->id) {
                $index = isset($resultIndexes[$call->id]) ? array_shift($resultIndexes[$call->id]) : null;
            }
            if (is_int($index)) {
                $result = $resultEntries[$index];
                $usedResultIndexes[$index] = true;
            }
            $projected[] = $this->tool($call->id, $result instanceof ToolResult ? $result->resultId : $call->resultId, $call->name, $result);
        }
        foreach ($resultEntries as $index => $result) {
            if (! isset($usedResultIndexes[$index])) {
                $projected[] = $this->tool($result->id, $result->resultId, $result->name, $result);
            }
        }

        if (count($calls) > $limit || count($results) > $limit || count($projected) > $limit) {
            $state->reason('limit');
        }

        return array_slice($projected, 0, $limit);
    }

    /** @return array<string, mixed> */
    private function tool(string $callId, ?string $resultId, string $name, ?ToolResult $result): array
    {
        return array_filter([
            'call_id' => $callId !== '' ? $callId : null,
            'result_id' => $resultId,
            'name' => $name !== '' ? $name : null,
            'status' => match (true) {
                $result === null => 'pending',
                $result->denied => 'denied',
                $result->failed => 'failed',
                default => 'succeeded',
            },
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function normalizeStructured(mixed $value, NativeStepResultProjectionState $state, int $depth = 0): mixed
    {
        if (is_null($value) || is_scalar($value)) {
            return $value;
        }
        if (! is_array($value)) {
            $state->reason('unsupported_type');

            return null;
        }
        if ($depth >= $this->limit('max_structured_depth', 32)) {
            $state->reason('limit');

            return null;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (++$state->structuredItems > $this->limit('max_structured_items', 4096)) {
                $state->reason('limit');

                break;
            }
            $normalized[$key] = $this->normalizeStructured($item, $state, $depth + 1);
        }

        return $normalized;
    }

    private function boundedString(string $value, int $maxBytes, NativeStepResultProjectionState $state): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        $state->reason('limit');

        return function_exists('mb_strcut') ? mb_strcut($value, 0, $maxBytes, 'UTF-8') : substr($value, 0, $maxBytes);
    }

    private function persistedString(mixed $value, NativeStepResultProjectionState $state): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }

        $state->reason('unsupported_type');

        return null;
    }

    private function persistedToolStatus(mixed $value, NativeStepResultProjectionState $state): ?string
    {
        $status = $this->persistedString($value, $state);
        if ($status === null) {
            return null;
        }
        if (in_array($status, self::TOOL_STATUSES, true)) {
            return $status;
        }

        $state->reason('unsupported_type');

        return null;
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private function persistedReasons(array $reasons, array $fallback = []): array
    {
        if (count($reasons) > self::MAX_PERSISTED_REASONS) {
            return ['limit'];
        }

        $normalized = [];
        foreach ($reasons as $reason) {
            if (strlen($reason) > self::MAX_PERSISTED_REASON_BYTES) {
                return ['limit'];
            }
            if ($reason !== '' && ! in_array($reason, $normalized, true)) {
                $normalized[] = $reason;
            }
        }

        return $normalized === [] ? $fallback : $normalized;
    }

    /** @return array<string, int|null>|null */
    private function usage(mixed $usage, NativeStepResultProjectionState $state): ?array
    {
        if ($usage === null) {
            return null;
        }
        if (! is_array($usage)) {
            $state->reason('unsupported_type');

            return null;
        }

        if (array_diff(array_keys($usage), self::USAGE_KEYS) !== []) {
            $state->reason('unsupported_type');
        }

        $normalized = [];
        foreach (self::USAGE_KEYS as $key) {
            if (! array_key_exists($key, $usage)) {
                continue;
            }
            if (! is_int($usage[$key]) && $usage[$key] !== null) {
                $state->reason('unsupported_type');

                continue;
            }
            $normalized[$key] = $usage[$key];
        }

        return $normalized === [] ? null : $normalized;
    }

    private function applyTotalLimit(NativeStepResult $result, NativeStepResultProjectionState $state, int $max, ?string $limitedStatus = null, string $reason = 'limit'): NativeStepResult
    {
        $candidate = $result;
        foreach ([null, 'structured', 'reasoning', 'generation_steps', 'tools', 'assistant_message_id', 'user_message_id', 'conversation_id', 'invocation_id', 'model', 'provider'] as $drop) {
            if ($drop !== null) {
                $state->reason($reason);
                $candidate = new NativeStepResult(
                    status: $limitedStatus ?? NativeStepResult::PARTIAL,
                    structured: $drop === 'structured' ? null : $candidate->structured,
                    reasoning: $drop === 'reasoning' ? null : $candidate->reasoning,
                    provider: $drop === 'provider' ? null : $candidate->provider,
                    model: $drop === 'model' ? null : $candidate->model,
                    invocationId: $drop === 'invocation_id' ? null : $candidate->invocationId,
                    conversationId: $drop === 'conversation_id' ? null : $candidate->conversationId,
                    userMessageId: $drop === 'user_message_id' ? null : $candidate->userMessageId,
                    assistantMessageId: $drop === 'assistant_message_id' ? null : $candidate->assistantMessageId,
                    generationSteps: $drop === 'generation_steps' ? [] : $candidate->generationSteps,
                    tools: $drop === 'tools' ? [] : $candidate->tools,
                    reasons: $state->reasons,
                );
            }
            $encoded = json_encode($candidate->toArray());
            if (is_string($encoded) && strlen($encoded) <= $max) {
                return $candidate;
            }
        }

        return new NativeStepResult(status: $limitedStatus ?? NativeStepResult::PARTIAL, reasons: [$reason]);
    }

    private function limit(string $key, int $default): int
    {
        $value = (int) $this->config->get('swarm.native_results.'.$key, $default);
        $hard = match ($key) {
            'max_bytes' => 16777216,
            'max_event_bytes' => 8192,
            'max_generation_steps', 'max_tool_statuses' => 4096,
            'max_structured_depth' => 128,
            'max_structured_items' => 100000,
            'max_reasoning_bytes' => 1048576,
            default => $default,
        };

        return max(in_array($key, ['max_bytes', 'max_event_bytes'], true) ? 256 : 1, min($value, $hard));
    }
}

/** Per-projection state. It is deliberately never retained by the singleton projector. @internal */
final class NativeStepResultProjectionState
{
    public int $structuredItems = 0;

    /** @var list<string> */
    public array $reasons = [];

    /** @param list<string> $reasons */
    public function __construct(array $reasons = [])
    {
        foreach ($reasons as $reason) {
            $this->reason($reason);
        }
    }

    public function reason(string $reason): void
    {
        if (! in_array($reason, $this->reasons, true)) {
            $this->reasons[] = $reason;
        }
    }
}
