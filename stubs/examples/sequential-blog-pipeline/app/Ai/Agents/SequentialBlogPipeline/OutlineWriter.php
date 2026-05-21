<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\SequentialBlogPipeline;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Step 1 of the sequential-blog-pipeline starter example.
 *
 * Extends ScriptedAgent so this example runs end-to-end with no provider
 * configured. To plug in a real LLM, replace `extends ScriptedAgent` with
 * `implements Agent` + `use Promptable;` and add `#[Provider]` / `#[Model]`
 * attributes — the rest of the swarm wiring stays identical.
 */
class OutlineWriter extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Draft a five-point outline for a blog post on the given topic. Return one bullet per line.';
    }

    protected function reply(string $prompt): string
    {
        // TODO: swap ScriptedAgent for a real Promptable agent to use a live model.
        return <<<OUTLINE
            Outline for: {$prompt}
            - Why this matters to the reader
            - Background and context
            - The core idea, illustrated
            - One concrete example
            - What to do next
            OUTLINE;
    }
}
