<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\AuthorizesNativeAgentConversation;

final class DenyNativeAgentConversations implements AuthorizesNativeAgentConversation
{
    public function authorize(?string $conversationId, object $participant, RunContext $context): bool
    {
        return false;
    }
}
