<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;
use BuiltByBerry\LaravelSwarm\Runners\ParallelRunner;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Runners\SequentialStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmGuardrailRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use Illuminate\Contracts\Foundation\Application;

/**
 * Swarm registers several orchestration services as singletons. They capture
 * {@see SwarmGuardrailRunner} in constructors; when tests change guardrail config
 * or container bindings, those singletons must be forgotten so the next resolve
 * picks up the new runner and config.
 */
final class GuardrailContainer
{
    public static function refresh(Application $app): void
    {
        foreach ([
            DurableSwarmManager::class,
            SwarmRunner::class,
            SequentialRunner::class,
            SequentialStreamRunner::class,
            ParallelRunner::class,
            HierarchicalRunner::class,
            SwarmGuardrailRunner::class,
        ] as $abstract) {
            $app->forgetInstance($abstract);
        }
    }
}
