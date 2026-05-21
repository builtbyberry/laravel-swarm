<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Swarms\DurableApprovalWorkflow;

use {{ rootNamespace }}\Ai\Agents\DurableApprovalWorkflow\PolicyDraftAgent;
use {{ rootNamespace }}\Ai\Agents\DurableApprovalWorkflow\PolicyFinalizeAgent;
use BuiltByBerry\LaravelSwarm\Attributes\DurableDetails;
use BuiltByBerry\LaravelSwarm\Attributes\DurableLabels;
use BuiltByBerry\LaravelSwarm\Attributes\Timeout;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableWaits;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * durable-approval-workflow — the showcase example.
 *
 * Topology: Sequential, executed in **durable** mode. Two agents bracket a
 * `durableWaits()` checkpoint: draft → wait for approval → finalize.
 *
 * Flow:
 *   1. `dispatchDurable()` enqueues the run; PolicyDraftAgent runs and
 *      checkpoints its output.
 *   2. After step 1 completes, `durableWaits()` reports a `policy_decision`
 *      wait. The runner persists the wait row and parks the run.
 *   3. An approver sends a signal — either via `php artisan swarm:signal
 *      <run-id> policy_decision --payload='{"approved":true}'` or via
 *      `DurableSwarmManager::signal()` from your app code.
 *   4. The signal releases the wait; the next queued job runs
 *      PolicyFinalizeAgent on the draft output.
 *
 * Demonstrates: dispatchDurable(), declarative durable waits, signal-driven
 * resume, durable labels and details for operator dashboards, durable
 * timeout for total run lifetime.
 *
 * Requires (real-app run):
 *   - SWARM_PERSISTENCE_DRIVER=database
 *   - SWARM_CAPTURE_ACTIVE_CONTEXT=true
 *   - package migrations have run
 *   - a queue worker on the durable connection
 *   - `swarm:recover` scheduled (handles wait timeouts and dropped leases)
 *
 * Next step: docs/durable-execution.md, docs/durable-waits-and-signals.md
 */
#[Timeout(86400)]
#[DurableLabels(['workflow' => 'policy-approval'])]
#[DurableDetails(['review_type' => 'human-in-the-loop'])]
class PolicyApprovalSwarm implements Swarm, RoutesDurableWaits
{
    use Runnable;

    /**
     * @return array<int, \BuiltByBerry\LaravelSwarm\Contracts\Agent>
     */
    public function agents(): array
    {
        return [
            new PolicyDraftAgent,
            new PolicyFinalizeAgent,
        ];
    }

    /**
     * Declare a wait between the draft and finalize steps.
     *
     * The runner checks this at each step boundary. Returning the wait only
     * after step 1 completes (`completed_steps >= 1`) means the draft runs
     * immediately, then the run parks until a `policy_decision` signal lands.
     *
     * @return array<int, array{name: string, timeout?: int|null, reason?: string|null, metadata?: array<string, mixed>}>
     */
    public function durableWaits(RunContext $context): array
    {
        $completed = (int) ($context->metadata['completed_steps'] ?? 0);

        if ($completed < 1) {
            return [];
        }

        return [
            [
                'name' => 'policy_decision',
                'timeout' => 86400,
                'reason' => 'Waiting for an approver to accept or reject the drafted policy.',
            ],
        ];
    }
}
