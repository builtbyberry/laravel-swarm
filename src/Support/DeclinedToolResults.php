<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Streaming\StreamEventMapper;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

/**
 * Marks the result of a tool call that declined its work as failed.
 *
 * A Laravel AI tool can only return a string, which is recorded as a
 * successful result, or throw, which ends the agent's run. A tool that wants
 * the run to continue while its result reads as failed therefore notes the
 * call on the active run ({@see ActiveRunContext::declineToolCall()}), and
 * the runners apply that note here before projecting or capturing the result.
 *
 * Prompted agents are covered by {@see NativeAgentInvoker::prompt()}. Streamed
 * results are not centralised: every place that handles a Laravel AI
 * `ToolResult` stream event must call {@see applyToStreamEvent()} first,
 * today {@see StreamEventMapper::map()} and
 * {@see StaticHierarchicalStreamRunner}. A new handler that skips it reports
 * declined calls as successful.
 *
 * @internal
 */
final class DeclinedToolResults
{
    public static function applyToResponse(AgentResponse $response): void
    {
        /** @var array<int, ToolResultData> $results */
        $results = [];

        foreach ($response->steps as $step) {
            foreach ($step->toolResults as $result) {
                $results[spl_object_id($result)] = $result;
            }
        }

        foreach ($response->toolResults as $result) {
            $results[spl_object_id($result)] = $result;
        }

        foreach ($results as $result) {
            if ($result->denied || $result->failed || ! is_string($result->result)) {
                continue;
            }

            if (ActiveRunContext::consumeDeclinedToolCall($response->invocationId, $result->id, $result->result)) {
                $result->failed = true;
            }
        }
    }

    public static function applyToStreamEvent(ToolResultEvent $event): void
    {
        if ($event->preliminary
            || $event->denied
            || ! $event->successful
            || ! is_string($event->toolResult->result)) {
            return;
        }

        if (! ActiveRunContext::consumeDeclinedToolCall(
            $event->invocationId,
            $event->toolResult->id,
            $event->toolResult->result,
        )) {
            return;
        }

        $event->toolResult->failed = true;
        $event->successful = false;
        $event->error = $event->toolResult->error();
    }
}
