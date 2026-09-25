<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Container\Container;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\ProviderTool;
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

        if (! (new ReflectionClass($agent))->isCloneable()) {
            throw new SwarmException("Native agent configuration [{$invocation->configurationId}] requires a cloneable agent so per-run state cannot leak across requests.");
        }
        $agent = clone $agent;

        if ($invocation->tools !== []) {
            if (! method_exists($agent, 'withTools')) {
                throw new SwarmException("Native agent configuration [{$invocation->configurationId}] targets an agent without Laravel AI's withTools API.");
            }

            $tools = array_map(static function (NativeAgentToolReference $reference): Agent|Tool|ProviderTool {
                $tool = Container::getInstance()->makeWith($reference->class, $reference->arguments);
                if (! $tool instanceof Agent && ! $tool instanceof Tool && ! $tool instanceof ProviderTool) {
                    throw new SwarmException("Native agent tool [{$reference->class}] must resolve to a Laravel AI Agent, Tool, or ProviderTool.");
                }

                return $tool;
            }, $invocation->tools);

            $agent->withTools($tools);
        }

        if ($invocation->messages !== [] || $invocation->clearMessages) {
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
}
