<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;

/** @internal Storage identity selection without changing native public event IDs. */
final class StreamEventIdentity
{
    public static function forEvent(SwarmStreamEvent $event): ?string
    {
        if ($event->branchId !== null && $event->attemptId !== null && $event->branchSequence !== null) {
            $runId = $event->toArray()['run_id'] ?? null;
            if (! is_string($runId)) {
                return null;
            }

            return 'parallel:'.hash('sha256', serialize([
                $runId,
                $event->branchId,
                $event->attemptId,
                $event->branchSequence,
            ]));
        }

        if ($event->storageEventId !== null) {
            return $event->storageEventId;
        }

        if ($event instanceof SwarmProviderToolEvent) {
            return $event->causalId();
        }

        if (($event instanceof SwarmToolCall || $event instanceof SwarmToolResult)
            && $event->nodeId !== null && $event->attemptEpoch !== null) {
            return 'function:'.hash('sha256', serialize([$event->type(), $event->runId,
                $event->stepIndex, $event->nodeId, $event->attemptEpoch, $event->invocationId, $event->id]));
        }

        $id = $event->toArray()['id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
