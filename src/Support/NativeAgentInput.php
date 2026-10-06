<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Messages\UserMessage;

final class NativeAgentInput
{
    public static function message(AgentInput $input): UserMessage
    {
        if ($input->decisions() !== null) {
            throw new SwarmException('Laravel AI approval decisions cannot start or resume a swarm run. Continue the owning agent approval interaction instead.');
        }

        $message = $input->message();
        if (! $message instanceof UserMessage) {
            throw new SwarmException('Laravel AI AgentInput must contain a user message to start a swarm run.');
        }

        return $message;
    }
}
