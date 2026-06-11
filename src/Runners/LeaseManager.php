<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\CoordinationProfile;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Throwable;

/**
 * Lease lifecycle helpers for the runner orchestrator.
 *
 * Owns the lease-seconds policy used for queued execution and the
 * coordination-run failure path that runs inside a freshly acquired durable
 * lease. Lease acquisition for non-coordination paths still flows through the
 * RunHistoryStore directly because the early-return shape (race lost → abandon
 * run) is part of orchestrator flow control.
 *
 * @internal
 */
class LeaseManager
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected SwarmCapture $capture,
    ) {}

    /**
     * Lease window for queued runs. Doubles the timeout so a worker
     * processing a long-running run has headroom before another worker
     * can reclaim the lease, with a 300s floor for very short timeouts.
     */
    public function resolveQueueLeaseSeconds(int $timeoutSeconds): int
    {
        return max($timeoutSeconds * 2, 300);
    }

    /**
     * Reclaim the durable coordination lease and mark the run failed.
     *
     * No-op when:
     *   - no durable row exists for this runId
     *   - the row is not a QueueHierarchicalParallel coordination run
     *   - another worker holds the lease
     *
     * Used exclusively by the queued-hierarchical post-join resume path where
     * the orchestrator detected an exception and needs to surface failure
     * through the coordination row in addition to the history row.
     */
    public function failCoordinationRunIfQueueHierarchicalParallel(string $runId, Throwable $exception): void
    {
        $fresh = $this->durableRuns->find($runId);

        if ($fresh === null || (($fresh['coordination_profile'] ?? '') !== CoordinationProfile::QueueHierarchicalParallel->value)) {
            return;
        }

        $stepTimeout = max(1, (int) ($fresh['step_timeout_seconds'] ?? 300));
        $token = $this->durableRuns->acquireLease($runId, (int) $fresh['next_step_index'], $stepTimeout);

        if ($token === null) {
            return;
        }

        $this->durableRuns->markFailed($runId, $token, $this->capture->failureArray($exception));
    }

    /**
     * Mark the coordination run completed inside a freshly acquired durable
     * lease. No-op when no durable row exists or another worker holds the
     * lease.
     */
    public function markCoordinationRunCompleted(string $runId): void
    {
        $fresh = $this->durableRuns->find($runId);

        if ($fresh === null) {
            return;
        }

        $stepTimeout = max(1, (int) ($fresh['step_timeout_seconds'] ?? 300));
        $token = $this->durableRuns->acquireLease($runId, (int) $fresh['next_step_index'], $stepTimeout);

        if ($token !== null) {
            $this->durableRuns->markCompleted($runId, $token);
        }
    }
}
