<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\HierarchicalSupportTriage;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Technical handler. The coordinator routes error/bug/login requests here.
 */
class TechnicalResponder extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Diagnose technical problems: errors, crashes, logins, and timeouts.';
    }

    protected function reply(string $prompt): string
    {
        // TODO: swap ScriptedAgent for a real Promptable agent to use a live model.
        return "[TechnicalResponder] {$prompt}\n"
            .'- Reproduced the failure and captured the stack trace.'."\n"
            .'- Suggested a workaround and filed a ticket for the root cause.';
    }
}
