<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolInputDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolInputError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolInputStart;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextStart;
use Throwable;

/**
 * The provider stream events both mapping chains ({@see StreamEventMapper} and
 * {@see StaticHierarchicalStreamRunner}) share beyond their own branches: the
 * tool-input events laravel/ai streams while a model writes a tool call's
 * arguments, and the lifecycle events Swarm deliberately does not map.
 *
 * Tool-input events are recognised by the `type` of their wire shape
 * (`StreamEvent::toArray()`), not by class: laravel/ai added them in v1.4.2 and
 * Swarm supports laravel/ai ^1.0, where the classes do not exist.
 *
 * Stateless, and the caller passes its own capture service, so a detached
 * process worker that builds its own capture keeps that exact capture policy.
 *
 * @internal
 */
final class ToolInputEventMapper
{
    /**
     * Whether the caller skips `$event` without a breadcrumb: a lifecycle marker
     * Swarm emits its own equivalent for (stream and step boundaries; a text or
     * reasoning block is opened by its first delta), or a tool-input event while
     * `$capture` does not stream tool input (opt-in). Neither carries content the
     * durable record must keep.
     */
    public static function skips(mixed $event, SwarmCapture $capture): bool
    {
        if ($event instanceof StreamStart || $event instanceof TextStart || $event instanceof ReasoningStart) {
            return true;
        }

        return ! $capture->streamsToolInput() && self::toolInputType($event) !== null;
    }

    /**
     * The Swarm event for a laravel/ai tool-input event, captured for `$context`;
     * null for any other value, including a non-object.
     */
    public static function map(
        mixed $event,
        SwarmCapture $capture,
        RunContext $context,
        int $stepIndex,
        string $agentClass,
    ): ?SwarmStreamEvent {
        $type = self::toolInputType($event);

        if ($type === null || ! $event instanceof StreamEvent) {
            return null;
        }

        $payload = $event->toArray();

        $decision = $capture->outputsDecision($context);
        $availability = PayloadAvailability::fromCaptureDecision($decision);
        $full = $decision === CaptureDecision::Full;
        $id = self::string($payload, 'id');
        $toolId = self::string($payload, 'tool_id');
        $timestamp = is_int($payload['timestamp'] ?? null) ? $payload['timestamp'] : SwarmStreamEvent::timestamp();

        $swarmEvent = match ($type) {
            'tool_input_start' => new SwarmToolInputStart(
                id: $id,
                runId: $context->runId,
                stepIndex: $stepIndex,
                agentClass: $agentClass,
                toolId: $toolId,
                toolName: self::string($payload, 'tool_name'),
                timestamp: $timestamp,
                payloadAvailability: $availability,
            ),
            'tool_input_delta' => new SwarmToolInputDelta(
                id: $id,
                runId: $context->runId,
                stepIndex: $stepIndex,
                agentClass: $agentClass,
                toolId: $toolId,
                delta: $full ? self::string($payload, 'delta') : null,
                timestamp: $timestamp,
                payloadAvailability: $availability,
            ),
            default => new SwarmToolInputError(
                id: $id,
                runId: $context->runId,
                stepIndex: $stepIndex,
                agentClass: $agentClass,
                toolId: $toolId,
                toolName: self::string($payload, 'tool_name'),
                input: $full ? self::string($payload, 'input') : null,
                // The same policy as a tool result's error text.
                error: match ($decision) {
                    CaptureDecision::Full => self::string($payload, 'error'),
                    CaptureDecision::Redact => '[redacted]',
                    CaptureDecision::Skip => null,
                },
                timestamp: $timestamp,
                payloadAvailability: $availability,
            ),
        };

        if (is_string($event->invocationId)) {
            $swarmEvent->withInvocationId($event->invocationId);
        }

        return $swarmEvent;
    }

    /**
     * The tool-input wire type of `$event`, or null for anything else. An
     * unrecognised event's toArray() is foreign code; if it throws, the event is
     * not tool input and falls through to the caller's never-throw breadcrumb.
     */
    private static function toolInputType(mixed $event): ?string
    {
        if (! $event instanceof StreamEvent) {
            return null;
        }

        try {
            $type = $event->toArray()['type'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return in_array($type, ['tool_input_start', 'tool_input_delta', 'tool_input_error'], true) ? $type : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function string(array $payload, string $key): string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : '';
    }
}
