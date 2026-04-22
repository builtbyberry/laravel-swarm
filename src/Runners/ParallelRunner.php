<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laravel\Ai\Responses\AgentResponse;

class ParallelRunner
{
    public function __construct(
        protected ConcurrencyManager $concurrency,
    ) {}

    /**
     * Run all agents concurrently with the same initial task.
     *
     * @param  float  $deadlineMonotonic  hrtime(true) deadline in nanoseconds
     * @return array{response: SwarmResponse, usage: array<string, int>}
     */
    public function run(
        Swarm $swarm,
        string $task,
        float $deadlineMonotonic,
        int $maxAgentExecutions,
        string $contextKey,
        CacheRepository $cache,
        int $contextTtlSeconds,
    ): array {
        if (hrtime(true) >= $deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout before parallel execution began.');
        }

        $agents = array_slice($swarm->agents(), 0, $maxAgentExecutions);

        $callbacks = [];
        foreach ($agents as $index => $agent) {
            $callbacks[$index] = function () use ($agent, $task): array {
                $response = $agent->prompt($task);

                return [
                    'output' => (string) $response,
                    'usage' => $response instanceof AgentResponse ? $response->usage->toArray() : [],
                    'class' => $agent::class,
                ];
            };
        }

        $driver = $this->concurrency->driver();

        /** @var array<int, array{output: string, usage: array<string, int>, class: string}> $results */
        $results = $driver->run($callbacks);

        if (hrtime(true) >= $deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout after parallel execution.');
        }

        $steps = [];
        $mergedUsage = [];
        $outputs = [];

        foreach ($agents as $index => $agent) {
            $row = $results[$index] ?? ['output' => '', 'usage' => [], 'class' => $agent::class];
            $steps[] = new SwarmStep(
                agentClass: $row['class'],
                input: $task,
                output: $row['output'],
            );

            $mergedUsage = $this->mergeUsage($mergedUsage, $row['usage']);
            $outputs[] = $row['output'];
        }

        $combined = implode("\n\n", $outputs);

        $cache->put($contextKey, [
            'topology' => 'parallel',
            'last_output' => $combined,
            'steps' => count($steps),
        ], $contextTtlSeconds);

        return [
            'response' => new SwarmResponse(
                output: $combined,
                steps: $steps,
                usage: $mergedUsage,
            ),
            'usage' => $mergedUsage,
        ];
    }

    /**
     * @param  array<string, int>  $accumulated
     * @param  array<string, int>  $next
     * @return array<string, int>
     */
    protected function mergeUsage(array $accumulated, array $next): array
    {
        foreach ($next as $key => $value) {
            $accumulated[$key] = ($accumulated[$key] ?? 0) + $value;
        }

        return $accumulated;
    }
}
