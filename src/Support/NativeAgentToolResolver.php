<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Contracts\Container\Container;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Throwable;

/** @internal */
final class NativeAgentToolResolver
{
    public static function resolve(NativeAgentToolReference $reference, Container $container): Agent|Tool|ProviderTool
    {
        if (! class_exists($reference->class)) {
            throw new SwarmException("Native agent tool [{$reference->class}] cannot be reconstructed because the class does not exist.");
        }

        try {
            $resolved = $container->makeWith($reference->class, $reference->arguments);
        } catch (Throwable $exception) {
            throw new SwarmException("Native agent tool [{$reference->class}] cannot be reconstructed from its declared arguments.", previous: $exception);
        }

        if (! $resolved instanceof Agent && ! $resolved instanceof Tool && ! $resolved instanceof ProviderTool) {
            throw new SwarmException("Native agent tool [{$reference->class}] must resolve to a Laravel AI Agent, Tool, or ProviderTool.");
        }

        return $resolved;
    }
}
