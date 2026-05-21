<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\ParallelResearchFanout;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

class CustomerScout extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Summarize the customer profile and their jobs to be done.';
    }

    protected function reply(string $prompt): string
    {
        // TODO: swap ScriptedAgent for a real Promptable agent to use a live model.
        return "[CustomerScout] Customer notes for: {$prompt}\n"
            .'- Primary persona: a Laravel team lead at a Series-A SaaS.'."\n"
            .'- Top job: ship reliable AI workflows without operating a research lab.';
    }
}
