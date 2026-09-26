<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\SequentialConversationMemory;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;
use BuiltByBerry\LaravelSwarm\Tools\Remember;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

/**
 * Step 1 of the sequential-conversation-memory starter example.
 *
 * Reads the customer's message (the task prompt), extracts the subject worth
 * remembering, and writes it to Run-scoped Swarm memory. The write goes through
 * the package's real {@see Remember} tool — the same memory-as-tool surface a
 * live model would call — so the example exercises the production write path
 * (capture policy, reserved-key guard, scope resolution) rather than reaching
 * into the store directly.
 *
 * The returned acknowledgement deliberately does NOT echo the subject. In a
 * sequential swarm each agent's output becomes the next agent's input, so if
 * the subject appeared here it could reach step two through the prompt. Omitting
 * it makes Swarm memory the ONLY channel that can carry it forward — which is
 * the whole point of the blueprint.
 *
 * Extends ScriptedAgent so the example runs end-to-end with no provider
 * configured: a real model would decide to call `remember`; the scripted
 * {@see reply()} invokes the same tool deterministically. For model behavior,
 * generate RequestListener with `make:agent`, then expose {@see Remember} from
 * its generated `tools()` method and port these instructions.
 */
class RequestListener extends ScriptedAgent
{
    /**
     * The Run-scoped memory key both agents agree on. Not `swarm:`-prefixed, so
     * it is a caller key the `remember` tool accepts (the reserved prefix is
     * rejected).
     */
    public const SUBJECT_KEY = 'customer.subject';

    public function instructions(): string
    {
        return 'Read the customer message, identify the subject (a support reference like "HD-2291" or a short summary), and remember it for the reply step. Acknowledge receipt without repeating the subject.';
    }

    protected function reply(string $prompt): string
    {
        // For model behavior, generate RequestListener with make:agent and expose
        // Remember through its tools() method so a live model calls `remember`
        // itself. This scripted reply invokes the exact same tool so the demo
        // genuinely writes to memory rather than faking it.
        (new Remember)->handle(new Request([
            'key' => self::SUBJECT_KEY,
            'value' => $this->subjectFrom($prompt),
            'scope' => MemoryScope::Run->value,
        ]));

        // Note: no subject in the acknowledgement — memory, not this string, is
        // what carries it to ReplyWriter.
        return 'Thanks for reaching out. Your message is logged and routed to the reply desk.';
    }

    /**
     * Derive the subject to remember: an explicit support reference when the
     * message contains one, otherwise a short summary so there is always a
     * concrete value to carry forward.
     */
    private function subjectFrom(string $message): string
    {
        if (preg_match('/\b[A-Z]{2,}-\d+\b/', $message, $matches) === 1) {
            return $matches[0];
        }

        $summary = Str::of($message)->squish()->words(8, '')->trim()->toString();

        return $summary === '' ? 'your request' : $summary;
    }
}
