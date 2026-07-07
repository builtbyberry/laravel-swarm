<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\SequentialConversationMemory;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;
use BuiltByBerry\LaravelSwarm\Tools\Recall;
use Laravel\Ai\Tools\Request;

/**
 * Step 2 of the sequential-conversation-memory starter example.
 *
 * Its prompt is step one's acknowledgement, which by design says nothing about
 * the subject. It reads the subject back from Run-scoped Swarm memory with the
 * package's real {@see Recall} tool and composes a reply that names it.
 *
 * Recall reads through the active swarm's propagation policy (the same path a
 * live model's `recall` call takes), so it only ever sees memory the policy
 * permits. The default policy surfaces Run-scoped entries to later agents, so
 * the subject RequestListener stored is visible here — no custom policy needed.
 *
 * Because the subject reaches this agent only through memory (never through the
 * prompt), the reply below is proof that the value written in step one shaped a
 * later step.
 */
class ReplyWriter extends ScriptedAgent
{
    public function instructions(): string
    {
        return 'Recall the subject remembered earlier in this run and write a short, specific reply that references it.';
    }

    protected function reply(string $prompt): string
    {
        // TODO: swap ScriptedAgent for a real Promptable agent and expose Recall
        // via a tools() method so a live model calls `recall` itself. This
        // scripted reply invokes the same tool and composes from what it returns.
        $recalled = (new Recall)->handle(new Request([
            'key' => RequestListener::SUBJECT_KEY,
            'scope' => MemoryScope::Run->value,
        ]));

        $subject = $this->subjectFrom($recalled);

        if ($subject === null) {
            // Defensive: recall found nothing (e.g. run outside a swarm). A real
            // reply desk would fall back rather than invent a reference.
            return 'I could not find the earlier request in memory, so I can only reply generically.';
        }

        return sprintf(
            'Re: %s — thanks for your patience. I have the details from your earlier message and a fix is on the way; we will follow up shortly.',
            $subject,
        );
    }

    /**
     * Parse the value out of the Recall tool's single-key render ("key: value").
     * A live model reads that tool result as context; the scripted agent parses
     * it deterministically. Returns null when the key was not found.
     */
    private function subjectFrom(string $recalled): ?string
    {
        $prefix = RequestListener::SUBJECT_KEY.': ';

        return str_starts_with($recalled, $prefix)
            ? substr($recalled, strlen($prefix))
            : null;
    }
}
