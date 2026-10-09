<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

enum ReplayMode: string
{
    /**
     * The agent re-executes against a frozen snapshot of Run-scope memory taken
     * at the original invocation. Run-scope writes and forgets are buffered and
     * saved through the live store after the retried step passes invocation,
     * guardrails, and step recording.
     *
     * This is the default and the recommended mode for reproducible durable runs.
     */
    case FrozenView = 'frozen_view';

    /**
     * The agent re-executes against live memory with no snapshot guard.
     * Use only when idempotency is guaranteed by external means or the swarm
     * explicitly opts out of deterministic replay.
     */
    case FreshExecution = 'fresh_execution';
}
