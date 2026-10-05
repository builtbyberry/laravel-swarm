<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

interface AuthorizesNativeAgentConversation
{
    /**
     * Authorize access to a native conversation for this run context.
     *
     * A null conversation ID means the invocation is starting a new conversation.
     * Returning false rejects the invocation with a SwarmException before any
     * existing-conversation ownership lookup or agent call.
     */
    public function authorize(?string $conversationId, object $participant, RunContext $context): bool;
}
