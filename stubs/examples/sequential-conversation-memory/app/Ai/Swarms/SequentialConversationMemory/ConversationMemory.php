<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Swarms\SequentialConversationMemory;

use {{ rootNamespace }}\Ai\Agents\SequentialConversationMemory\ReplyWriter;
use {{ rootNamespace }}\Ai\Agents\SequentialConversationMemory\RequestListener;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

/**
 * sequential-conversation-memory — Swarm memory shared across steps.
 *
 * Topology: Sequential. Two agents run in order and share a fact through
 * Swarm memory rather than only through the prompt chain:
 *
 *   RequestListener — reads the customer's message, pulls out the subject
 *                     (a support reference or a short summary), and writes it
 *                     to Run-scoped memory with the `remember` tool. Its own
 *                     reply deliberately OMITS the subject.
 *   ReplyWriter     — reads it back with the `recall` tool and composes a reply
 *                     that names the subject.
 *
 * Because step one's output never contains the subject, the only channel that
 * can carry it to step two is Swarm memory. That is what this blueprint proves:
 * a value written in an earlier step demonstrably shapes a later one.
 *
 * Scope & propagation. The write lands in the {@see \BuiltByBerry\LaravelSwarm\Enums\MemoryScope::Run}
 * scope — the run's own shared context. The default propagation policy presents
 * Run-scoped memory to every later agent in the run, so no custom policy is
 * needed for a within-run handoff. (Cross-RUN memory — remembering a caller
 * across separate invocations — is the Conversation scope, which needs a
 * conversation id bound to the run; see the README's "Next step".)
 *
 * Replay note. Under the default FrozenView replay mode, a crash-resumed final
 * step recalls against the memory snapshot frozen at its original invocation —
 * which already contains step one's write — so the recalled value, and the
 * reply it shapes, stay identical across a replay.
 *
 * Demonstrates: the `remember` / `recall` memory-as-tool surface, Run scope and
 * default propagation, Sequential topology, the Runnable trait, and a value
 * flowing between agents through memory rather than the prompt.
 *
 * Next step: docs/memory.md, docs/memory-recipes.md
 */
#[Topology(TopologyEnum::Sequential)]
class ConversationMemory implements Swarm
{
    use Runnable;

    /**
     * @return array<int, \Laravel\Ai\Contracts\Agent>
     */
    public function agents(): array
    {
        return [
            new RequestListener,
            new ReplyWriter,
        ];
    }
}
