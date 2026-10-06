<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\SequentialBlogPipeline;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Step 3 of the sequential-blog-pipeline starter example.
 *
 * Final pass: receives the Drafter's reply and returns a polished version.
 * The Polisher's output becomes the SwarmResponse->output value.
 */
class Polisher extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Tighten the draft for clarity and grammar. Preserve meaning.';
    }

    protected function reply(string $prompt): string
    {
        // For model behavior, generate Polisher with make:agent and port these instructions.
        return <<<POLISHED
            Polished blog post (scripted demo output):

            {$prompt}

            ---
            Polish notes: trimmed redundant phrasing, normalized voice to second person,
            tightened the closing call to action.
            POLISHED;
    }
}
