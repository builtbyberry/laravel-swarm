<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

/** @internal */
final class NativeAgentInvoker
{
    public static function prompt(Agent $agent, NativeAgentInvocation $invocation): AgentResponse
    {
        if ($invocation->timeout !== null) {
            return $agent->prompt($invocation->prompt, [], $invocation->provider, $invocation->model, $invocation->timeout);
        }

        if ($invocation->model !== null) {
            return $agent->prompt($invocation->prompt, [], $invocation->provider, $invocation->model);
        }

        if ($invocation->provider !== null) {
            return $agent->prompt($invocation->prompt, [], $invocation->provider);
        }

        return $agent->prompt($invocation->prompt);
    }

    public static function stream(Agent $agent, NativeAgentInvocation $invocation): StreamableAgentResponse
    {
        if ($invocation->timeout !== null) {
            return $agent->stream($invocation->prompt, [], $invocation->provider, $invocation->model, $invocation->timeout);
        }

        if ($invocation->model !== null) {
            return $agent->stream($invocation->prompt, [], $invocation->provider, $invocation->model);
        }

        if ($invocation->provider !== null) {
            return $agent->stream($invocation->prompt, [], $invocation->provider);
        }

        return $agent->stream($invocation->prompt);
    }
}
