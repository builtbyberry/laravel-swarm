<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;

/**
 * Result of {@see MemoryReplayCoordinator::begin()}: the snapshot-backed memory
 * boundary for a generator-based (streaming) agent invocation.
 *
 * A boundary is either:
 *
 * - **Fresh execution** — `$snapshot` and `$original` are both null. Replay is
 *   disabled or no prior crashed attempt exists; the runner freezes a new
 *   snapshot as usual and {@see MemoryReplayCoordinator::end()} is a no-op.
 * - **Replay** — `$snapshot` is the frozen prior attempt and `$original` is the
 *   live `SwarmMemory` binding captured before the swap. The container is now
 *   serving a {@see ReplaySwarmMemory}; the runner replays under the frozen
 *   view and must call {@see MemoryReplayCoordinator::end()} to restore
 *   `$original`.
 *
 * @internal
 */
final readonly class ReplayBoundary
{
    private function __construct(
        public ?MemorySnapshot $snapshot,
        public ?SwarmMemory $original,
    ) {}

    public static function freshExecution(): self
    {
        return new self(snapshot: null, original: null);
    }

    public static function replay(MemorySnapshot $snapshot, SwarmMemory $original): self
    {
        return new self(snapshot: $snapshot, original: $original);
    }

    /**
     * True when a prior crashed attempt was found and the binding was swapped
     * to a snapshot-backed {@see ReplaySwarmMemory}.
     */
    public function isReplay(): bool
    {
        return $this->snapshot !== null;
    }
}
