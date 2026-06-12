<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\StreamStepCheckpointStore;

/**
 * No-op {@see StreamStepCheckpointStore} used when persistence runs in `cache`
 * mode and the `swarm_stream_step_checkpoints` table is not migrated.
 *
 * {@see find()} always returns null, so every non-final step takes the
 * fresh-execution path (re-executing on resume, the pre-#202 behaviour) and
 * {@see record()} is a no-op. Bound in lockstep with {@see NullSnapshotsMemory}
 * so cache-driver tests and ephemeral workloads stay green without a useless
 * table.
 *
 * @internal
 */
final class NullStreamStepCheckpointStore implements StreamStepCheckpointStore
{
    public function record(string $runId, int $stepIndex, string $output, array $usage): void
    {
        // No-op: nothing is persisted, so resume cannot skip steps.
    }

    public function find(string $runId, int $stepIndex): ?StreamStepCheckpoint
    {
        return null;
    }
}
