<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;

/**
 * Result of {@see MemoryReplayCoordinator::begin()}: the snapshot-backed memory
 * boundary for a generator-based (streaming) agent invocation.
 *
 * A boundary is either:
 *
 * - **Fresh execution** — `$snapshot` is null. Replay is disabled or no prior
 *   crashed attempt exists; the runner freezes a new snapshot as usual and
 *   {@see MemoryReplayCoordinator::end()} is a no-op.
 * - **Replay** — `$snapshot` is the frozen prior attempt and `$memory` retains
 *   the {@see ReplaySwarmMemory} installed as the per-invocation override on
 *   the active {@see ActiveRunContext} frame (not the container). The runner
 *   replays under the frozen view, calls {@see MemoryReplayCoordinator::end()}
 *   to clear the override, and passes this boundary to
 *   {@see MemoryReplayCoordinator::commit()} only after invocation, guardrails,
 *   and step recording succeed.
 *
 * @internal
 */
final readonly class ReplayBoundary
{
    private function __construct(
        public ?MemorySnapshot $snapshot,
        public ?ReplaySwarmMemory $memory,
    ) {}

    public static function freshExecution(): self
    {
        return new self(snapshot: null, memory: null);
    }

    public static function replay(MemorySnapshot $snapshot, ReplaySwarmMemory $memory): self
    {
        return new self(snapshot: $snapshot, memory: $memory);
    }

    /**
     * True when a prior crashed attempt was found and the frozen-memory
     * override was installed on the active frame.
     */
    public function isReplay(): bool
    {
        return $this->snapshot !== null;
    }
}
