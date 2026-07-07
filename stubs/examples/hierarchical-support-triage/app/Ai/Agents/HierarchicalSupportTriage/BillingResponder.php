<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\HierarchicalSupportTriage;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * One of three worker handlers the coordinator can route to.
 *
 * Worker agents receive the `prompt` the coordinator wrote for their route
 * node — not the original task and not the previous step's output. They only
 * run when the coordinator's plan routes to them.
 */
class BillingResponder extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Resolve billing questions: refunds, invoices, charges, and subscriptions.';
    }

    protected function reply(string $prompt): string
    {
        // TODO: swap ScriptedAgent for a real Promptable agent to use a live model.
        return "[BillingResponder] {$prompt}\n"
            .'- Pulled the account ledger and the last three invoices.'."\n"
            .'- Proposed a prorated adjustment and a link to the billing portal.';
    }
}
