<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\SequentialBlogPipeline;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Step 2 of the sequential-blog-pipeline starter example.
 *
 * Receives the OutlineWriter's output as its prompt and returns a draft.
 * That is the sequential topology contract: each agent's reply becomes the
 * next agent's input.
 */
class Drafter extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Turn the outline into a 4-paragraph draft. Keep the section order.';
    }

    protected function reply(string $prompt): string
    {
        // TODO: swap ScriptedAgent for a real Promptable agent to use a live model.
        return <<<DRAFT
            Draft based on outline above.

            Opening paragraph that hooks the reader on the core idea, written in plain language.
            Body paragraph that develops the background and the central illustration.
            Body paragraph that walks through the concrete example end to end.
            Closing paragraph with a clear next step the reader can take today.

            ---
            Source outline:
            {$prompt}
            DRAFT;
    }
}
