<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\DurableApprovalWorkflow;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Step 1 of the durable-approval-workflow starter example.
 *
 * Drafts a policy change for human review. After this step completes, the
 * swarm's `durableWaits()` declaration pauses the run until an approver
 * sends a `policy_decision` signal via `swarm:signal`.
 */
class PolicyDraftAgent extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Draft a clear, one-paragraph policy proposal for the given change request.';
    }

    protected function reply(string $prompt): string
    {
        // For model behavior, generate PolicyDraftAgent with make:agent and port these instructions.
        return <<<DRAFT
            Proposed policy change (scripted draft):

            {$prompt}

            Effective date: pending approval.
            Rollback plan: revert via the previous policy commit; no data migrations are involved.
            Risk: low — change is reversible within one business day.
            DRAFT;
    }
}
