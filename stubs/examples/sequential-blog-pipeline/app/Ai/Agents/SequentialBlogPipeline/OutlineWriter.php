<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\SequentialBlogPipeline;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Step 1 of the sequential-blog-pipeline starter example.
 *
 * Extends ScriptedAgent so this deterministic offline example runs with no
 * provider configured. For model behavior, generate OutlineWriter with Laravel
 * AI's `make:agent` command and port the application-owned instructions.
 */
class OutlineWriter extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Draft a five-point outline for a blog post on the given topic. Return one bullet per line.';
    }

    protected function reply(string $prompt): string
    {
        // For model behavior, generate OutlineWriter with make:agent and port these instructions.
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
