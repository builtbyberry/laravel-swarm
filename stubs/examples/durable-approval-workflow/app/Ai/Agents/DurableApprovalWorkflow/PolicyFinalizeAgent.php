<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\DurableApprovalWorkflow;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Step 2 of the durable-approval-workflow starter example.
 *
 * Runs after the approver signals `policy_decision`. Receives the draft from
 * the previous step as its prompt. In a real workflow this is where you would
 * publish the policy, write an audit row, send notifications, etc.
 */
class PolicyFinalizeAgent extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Finalize and publish the approved policy. Summarize the approved version for the run record.';
    }

    protected function reply(string $prompt): string
    {
        // TODO: swap ScriptedAgent for a real Promptable agent to use a live model.
        return <<<FINAL
            Final policy (scripted finalize output):

            {$prompt}

            Status: approved and published.
            Audit note: signal-driven approval recorded on the durable run.
            FINAL;
    }
}
