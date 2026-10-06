<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

interface AuthorizesNativeAgentConversation
{
    public function authorize(?string $conversationId, object $participant, RunContext $context): bool;
}
