<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Streaming\StreamEvent;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Str;

abstract class SwarmStreamEvent extends StreamEvent
{
    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    public static function newId(): string
    {
        return (string) Str::uuid();
    }

    public static function timestamp(): int
    {
        return time();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $event = match ($payload['type'] ?? null) {
            'swarm_stream_start' => SwarmStreamStart::fromArray($payload),
            'swarm_step_start' => SwarmStepStart::fromArray($payload),
            'swarm_text_delta' => SwarmTextDelta::fromArray($payload),
            'swarm_text_end' => SwarmTextEnd::fromArray($payload),
            'swarm_reasoning_delta' => SwarmReasoningDelta::fromArray($payload),
            'swarm_reasoning_end' => SwarmReasoningEnd::fromArray($payload),
            'swarm_tool_call' => SwarmToolCall::fromArray($payload),
            'swarm_tool_result' => SwarmToolResult::fromArray($payload),
            'swarm_step_end' => SwarmStepEnd::fromArray($payload),
            'swarm_stream_end' => SwarmStreamEnd::fromArray($payload),
            'swarm_stream_error' => SwarmStreamError::fromArray($payload),
            'swarm_causal_void_edge' => SwarmCausalVoidEdge::fromArray($payload),
            'swarm_node_opened' => SwarmNodeOpened::fromArray($payload),
            'swarm_node_children_decided' => SwarmNodeChildrenDecided::fromArray($payload),
            'swarm_node_closed' => SwarmNodeClosed::fromArray($payload),
            default => throw new SwarmException('Unknown persisted swarm stream event type ['.($payload['type'] ?? 'null').'].'),
        };

        if (is_string($payload['invocation_id'] ?? null)) {
            $event->withInvocationId($payload['invocation_id']);
        }

        // Restore the structural node tag (#284), mirroring invocation_id. An
        // absent key on a pre-grammar log leaves nodeId null — a top-level event.
        if (is_string($payload['node_id'] ?? null)) {
            $event->withNodeId($payload['node_id']);
        }

        return $event;
    }

    public function toStreamedEvent(): StreamedEvent
    {
        return new StreamedEvent($this->type(), $this->toArray());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function stringValue(array $payload, string $key, string $default = ''): string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : $default;
    }

    /**
     * Read a value that may be absent/null because a CaptureDecision::Skip
     * policy omitted it. Returns null when the key is missing or not a string.
     *
     * @param  array<string, mixed>  $payload
     */
    protected static function nullableStringValue(array $payload, string $key): ?string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function intValue(array $payload, string $key, int $default = 0): int
    {
        return is_int($payload[$key] ?? null) ? $payload[$key] : $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function nullableIntValue(array $payload, string $key): ?int
    {
        return is_int($payload[$key] ?? null) ? $payload[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function arrayValue(array $payload, string $key): array
    {
        return is_array($payload[$key] ?? null) ? $payload[$key] : [];
    }

    /**
     * Read an ordered list of strings — the declared child order of a
     * structural node (#284). Non-string entries are dropped rather than
     * coerced, and the surviving order is preserved (it is the contract).
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    protected static function stringList(array $payload, string $key): array
    {
        return array_values(array_filter(
            self::arrayValue($payload, $key),
            'is_string',
        ));
    }
}
