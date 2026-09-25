<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Container\Container;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use ReflectionClass;

/** @internal */
final class NativeAgentInvoker
{
    public static function prompt(Agent $agent, NativeAgentInvocation $invocation): AgentResponse
    {
        $agent = self::configured($agent, $invocation);

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
        $agent = self::configured($agent, $invocation);

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

    protected static function configured(Agent $agent, NativeAgentInvocation $invocation): Agent
    {
        if ($invocation->configurationId === null) {
            return $agent;
        }

        self::assertConfigurationCompatible(
            $agent,
            $invocation->configurationId,
            $invocation->toolsConfigured,
            $invocation->messagesConfigured,
            $invocation->conversation,
        );
        $agent = clone $agent;

        if ($invocation->toolsConfigured) {
            if (! method_exists($agent, 'withTools')) {
                throw new SwarmException("Native agent configuration [{$invocation->configurationId}] targets an agent without Laravel AI's withTools API.");
            }

            $tools = array_map(
                static fn (NativeAgentToolReference $reference): mixed => NativeAgentToolResolver::resolve($reference, Container::getInstance()),
                $invocation->tools,
            );

            $agent->withTools($tools);
        }

        if ($invocation->messagesConfigured) {
            if (! method_exists($agent, 'withMessages')) {
                throw new SwarmException("Native agent configuration [{$invocation->configurationId}] targets an agent without Laravel AI's withMessages API.");
            }

            $agent->withMessages($invocation->messages);
        }

        if ($invocation->conversation !== null) {
            if (! $agent instanceof Conversational
                || ! method_exists($agent, 'forParticipant')
                || ! method_exists($agent, 'continue')) {
                throw new SwarmException("Native agent configuration [{$invocation->configurationId}] targets an agent that does not expose Laravel AI's native conversation APIs.");
            }

            $participant = $invocation->conversation->participant();
            if ($invocation->conversation->conversationId === null) {
                $agent->forParticipant($participant);
            } else {
                $agent->continue($invocation->conversation->conversationId, as: $participant);
            }
        }

        return $agent;
    }

    public static function assertCompatible(Agent $agent, NativeInputRecipient $recipient): void
    {
        if (! $recipient->hasNativeSettings()) {
            return;
        }

        self::assertConfigurationCompatible(
            $agent,
            $recipient->settingsId(),
            $recipient->toolsConfigured,
            $recipient->messagesConfigured,
            $recipient->conversation,
        );
    }

    protected static function assertConfigurationCompatible(
        Agent $agent,
        string $configurationId,
        bool $toolsConfigured,
        bool $messagesConfigured,
        ?NativeAgentConversation $conversation,
    ): void {
        if (! (new ReflectionClass($agent))->isCloneable()) {
            throw new SwarmException("Native agent configuration [{$configurationId}] requires a cloneable agent so per-run state cannot leak across requests.");
        }
        if ($toolsConfigured && ! method_exists($agent, 'withTools')) {
            throw new SwarmException("Native agent configuration [{$configurationId}] targets an agent without Laravel AI's withTools API.");
        }
        if ($messagesConfigured && $agent instanceof Conversational) {
            throw new SwarmException("Native agent configuration [{$configurationId}] cannot apply withMessages to a Laravel AI Conversational agent. Use NativeAgentConversation instead.");
        }
        if ($messagesConfigured && ! method_exists($agent, 'withMessages')) {
            throw new SwarmException("Native agent configuration [{$configurationId}] targets an agent without Laravel AI's withMessages API.");
        }
        if ($conversation !== null && (! $agent instanceof Conversational
            || ! method_exists($agent, 'forParticipant')
            || ! method_exists($agent, 'continue'))) {
            throw new SwarmException("Native agent configuration [{$configurationId}] targets an agent that does not expose Laravel AI's native conversation APIs.");
        }
    }
}
