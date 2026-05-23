<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events\Memory;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\ReplaySwarmMemory;

/**
 * Fired when a replay-mode read targets a scope that the frozen snapshot does
 * not cover (anything other than the snapshotted Run scope).
 *
 * The v0.9.0 snapshot captures only `MemoryScope::Run` entries — Conversation,
 * Agent, and Swarm scope are read-through to the live store during replay.
 * Those values may have drifted since the original invocation, which is a
 * controlled determinism leak: pragmatic enough not to break working agents
 * that read shared knowledge, but the audit trail needs to know it happened
 * so compliance can decide whether that drift is acceptable for their workload.
 *
 * Dispatched by {@see ReplaySwarmMemory} on every cross-scope read or write
 * attempt during replay. Listeners can flip it into a hard failure for
 * stricter compliance postures by registering a subscriber that throws.
 *
 * No value payload is exposed — same redaction-safety stance as
 * {@see MemoryRead}.
 */
final class MemoryScopeOutOfSnapshot
{
    public function __construct(
        public readonly string $runId,
        public readonly int $stepIndex,
        public readonly MemoryScope $scope,
        public readonly string $scopeId,
        public readonly string $key,
        public readonly string $operation,
    ) {}
}
