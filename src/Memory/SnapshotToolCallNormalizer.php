<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\TextResponse;

/**
 * Normalize Laravel AI tool-call records into the plain-data shape persisted
 * to a {@see MemorySnapshot}'s `tool_calls` column.
 *
 * Each persisted entry has the shape:
 *
 *   [
 *     'id'        => string|null,   // ToolCall id (provider-assigned)
 *     'name'      => string,
 *     'arguments' => array<string,mixed>,
 *     'result'    => mixed|null,    // result payload, or null if no result paired
 *     'result_id' => string|null,
 *   ]
 *
 * The normalizer pairs each `ToolCall` to its matching `ToolResult` by
 * `(toolCall.resultId, toolResult.id)` when present, falling back to
 * `(toolCall.id, toolResult.id)`. Unpaired calls land with `result => null`
 * so replay can detect partial runs.
 *
 * @internal
 */
final class SnapshotToolCallNormalizer
{
    /**
     * Extract tool-call entries from a Laravel AI response value.
     *
     * @return array<int, array{name: string, arguments: array<string, mixed>, result: mixed, id: string|null, result_id: string|null}>
     */
    public static function fromResponse(mixed $response): array
    {
        // StructuredTextResponse, AgentResponse, and StructuredAgentResponse
        // all extend TextResponse, so this single instanceof check is
        // sufficient to cover every Laravel AI response shape that carries
        // tool-call collections.
        if (! $response instanceof TextResponse) {
            return [];
        }

        // laravel/ai ^0.9 types these as Collection<int, ToolCall> /
        // Collection<int, ToolResult>, so `->all()` is already precisely typed
        // across the boundary — no local @var override needed.
        return self::pair(
            $response->toolCalls->all(),
            $response->toolResults->all(),
        );
    }

    /**
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, ToolResult>  $toolResults
     * @return array<int, array{name: string, arguments: array<string, mixed>, result: mixed, id: string|null, result_id: string|null}>
     */
    public static function pair(array $toolCalls, array $toolResults): array
    {
        $resultsByLookupId = [];

        foreach ($toolResults as $result) {
            // Laravel AI tool results expose `id` (the call id they're answering)
            // and `resultId`. Index by `id` first so we can resolve by the
            // tool-call's `resultId` (when set) or by its own `id`.
            $resultsByLookupId[$result->id] = $result;
        }

        $entries = [];

        foreach ($toolCalls as $call) {
            $resultLookupId = $call->resultId ?? $call->id;
            $matched = $resultsByLookupId[$resultLookupId] ?? null;

            $entries[] = [
                'id' => $call->id,
                'name' => $call->name,
                'arguments' => $call->arguments,
                'result' => $matched?->result,
                'result_id' => $matched !== null ? $matched->resultId : $call->resultId,
            ];
        }

        return $entries;
    }

    /**
     * Build a single tool-call entry from already-paired data, used by the
     * streaming runner which sees `ToolCall` and `ToolResult` events flow by
     * one at a time.
     *
     * @return array{name: string, arguments: array<string, mixed>, result: mixed, id: string|null, result_id: string|null}
     */
    public static function entry(ToolCall $call, ?ToolResult $result = null): array
    {
        return [
            'id' => $call->id,
            'name' => $call->name,
            'arguments' => $call->arguments,
            'result' => $result?->result,
            'result_id' => $result !== null ? $result->resultId : $call->resultId,
        ];
    }
}
