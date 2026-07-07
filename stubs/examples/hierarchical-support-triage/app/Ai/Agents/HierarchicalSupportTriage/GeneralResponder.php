<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\HierarchicalSupportTriage;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Catch-all handler. The coordinator routes here when a request is neither
 * billing nor technical.
 */
class GeneralResponder extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Handle general questions that are neither billing nor technical.';
    }

    protected function reply(string $prompt): string
    {
        // TODO: swap ScriptedAgent for a real Promptable agent to use a live model.
        return "[GeneralResponder] {$prompt}\n"
            .'- Answered from the help center and pointed to the getting-started guide.'."\n"
            .'- Offered to escalate if the request needs a specialist.';
    }
}
