<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;

/**
 * Raised when a caller attempts to mutate a {@see MemorySnapshot} that has
 * been loaded as the canonical record of a completed step.
 *
 * Thrown from {@see SnapshotsMemory::appendToolCall()} when the supplied
 * snapshot has `$frozen === true`. The canonical record is the audit-defensible
 * source of truth for replay; silently allowing a write would corrupt that
 * guarantee and is exactly the failure mode the v0.9.0 memory subsystem is
 * designed to prevent.
 *
 * Callers that legitimately need to rewrite tool calls on a snapshot — the
 * durable mid-flight retry path is the only one today — must first obtain an
 * unfrozen copy via {@see SnapshotsMemory::resetToolCalls()}, which records
 * the reset as a deliberate action rather than swallowing it.
 */
class SnapshotFrozenException extends SwarmException
{
    public static function forStep(string $runId, int $stepIndex): self
    {
        return new self(sprintf(
            'Cannot mutate a frozen memory snapshot for run [%s] step [%d]. '
            .'The snapshot is the canonical record of a completed step; '
            .'call SnapshotsMemory::resetToolCalls() first if you need to '
            .'rebuild tool calls on a mid-flight retry.',
            $runId,
            $stepIndex,
        ));
    }
}
