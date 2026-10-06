<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Support\AdHocSwarm;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Laravel\Ai\Contracts\Agent;

/** @internal */
final class ParallelAgentResolver
{
    /**
     * Reconstruct one authored parallel slot inside a worker process.
     *
     * @param  class-string  $swarmClass
     * @param  class-string  $agentClass
     */
    public function resolve(string $swarmClass, string $agentClass, int $index, bool $adHoc): Agent
    {
        if ($adHoc) {
            $agent = Container::getInstance()->make($agentClass);
        } else {
            $workerSwarm = Container::getInstance()->make($swarmClass);
            if (! $workerSwarm instanceof Swarm) {
                throw new SwarmException("Parallel swarm [{$swarmClass}] must be container-resolvable in worker processes.");
            }

            $agent = $workerSwarm->agents()[$index] ?? null;
            if ($agent instanceof Agent && $agent::class !== $agentClass) {
                try {
                    $agent = Container::getInstance()->make($agentClass);
                } catch (BindingResolutionException $exception) {
                    throw new SwarmException(
                        "{$swarmClass}: parent-selected parallel agent [{$agentClass}] must be container-resolvable when worker configuration selects a different class for slot [{$index}].",
                        previous: $exception,
                    );
                }
            }
        }

        if (! $agent instanceof Agent) {
            throw new SwarmException("Parallel swarm agent [{$agentClass}] must resolve to a Laravel AI agent.");
        }

        return $agent;
    }

    public function ensureResolvable(Swarm $swarm): void
    {
        $agents = $swarm->agents();
        $swarmClass = $swarm::class;

        if ($swarm instanceof AdHocSwarm) {
            foreach ($agents as $agent) {
                $agentClass = $agent::class;
                try {
                    $resolved = Container::getInstance()->make($agentClass);
                } catch (BindingResolutionException $exception) {
                    throw new SwarmException(
                        "{$swarmClass}: parallel agent [{$agentClass}] must be container-resolvable because Laravel Concurrency serializes worker callbacks.",
                        previous: $exception,
                    );
                }

                if (! $resolved instanceof Agent) {
                    throw new SwarmException("{$swarmClass}: parallel agent [{$agentClass}] must resolve to a Laravel AI agent.");
                }
            }

            return;
        }

        try {
            $resolvedSwarm = Container::getInstance()->make($swarmClass);
        } catch (BindingResolutionException $exception) {
            throw new SwarmException(
                "{$swarmClass}: authored parallel swarms must be container-resolvable so worker slots can be reconstructed.",
                previous: $exception,
            );
        }

        if (! $resolvedSwarm instanceof Swarm) {
            throw new SwarmException("{$swarmClass}: authored parallel swarm must resolve to a swarm in worker processes.");
        }

        foreach (array_keys($agents) as $index) {
            if (! ($resolvedSwarm->agents()[$index] ?? null) instanceof Agent) {
                throw new SwarmException("{$swarmClass}: authored parallel agent slot [{$index}] must reconstruct to a Laravel AI agent.");
            }
        }
    }
}
