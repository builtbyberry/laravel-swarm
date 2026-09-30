<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\NativeMediaRetrieval;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;

/**
 * Step 3 of native-media-retrieval: write the final grounded answer from the
 * ranked passages produced by {@see RetrievalAgent}.
 *
 * Extends ScriptedAgent so the example runs offline. For model behavior, generate
 * this agent with `make:agent` and keep these instructions.
 */
class AnswerSynthesizer extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Answer the question using only the retrieved passages. Ground the answer in the top passage and do not invent facts.';
    }

    protected function reply(string $prompt): string
    {
        // Input is the retrieval agent's JSON. Ground the answer in the top passage.
        $decoded = json_decode($prompt, true);
        $passages = is_array($decoded) && isset($decoded['passages']) && is_array($decoded['passages'])
            ? $decoded['passages']
            : [];

        if ($passages === []) {
            return 'No relevant passages were retrieved for this question.';
        }

        return 'Based on the knowledge base: '.$passages[0];
    }
}
