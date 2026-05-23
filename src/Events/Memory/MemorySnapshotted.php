<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events\Memory;

/**
 * Fired after a per-step memory snapshot is captured for run replay.
 *
 * The snapshot mechanism itself ships separately as part of issue #111
 * (replay-friendly memory snapshots). This event class defines the stable
 * payload shape so app listeners can be authored ahead of the snapshot
 * implementation landing, and so other store implementations or future
 * snapshot drivers can dispatch a uniform event when they capture a snapshot.
 *
 * Once #111 lands, the snapshot mechanism will dispatch this event after
 * persisting each snapshot row to `swarm_memory_snapshots`. Until then no
 * production code path in this package emits the event — it is intentionally
 * defined here so the listener contract is part of the v0.9.0 surface.
 *
 * Payload:
 *
 * - `runId`      — the swarm run whose memory was snapshotted.
 * - `stepIndex`  — the zero-based step index the snapshot was taken at.
 * - `snapshotId` — opaque identifier for the persisted snapshot row.
 */
final class MemorySnapshotted
{
    public function __construct(
        public readonly string $runId,
        public readonly int $stepIndex,
        public readonly string $snapshotId,
    ) {}
}
