<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\ParallelResearchFanout;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

class CompetitorScout extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Identify likely competitors and how they position.';
    }

    protected function reply(string $prompt): string
    {
        // For model behavior, generate CompetitorScout with make:agent and port these instructions.
        return "[CompetitorScout] Competitor notes for: {$prompt}\n"
            .'- Two direct competitors, both bundle a dashboard.'."\n"
            .'- One indirect competitor wins on integrations breadth.';
    }
}
