<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

enum ReplayMode: string
{
    /**
     * The agent re-executes against a frozen snapshot of Run-scope memory taken
     * at the original invocation. Live memory writes are buffered and never reach
     * the backing store, preserving the canonical audit record.
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
