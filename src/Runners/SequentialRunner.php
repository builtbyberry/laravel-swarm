<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laravel\Ai\Responses\AgentResponse;

class SequentialRunner
{
    /**
     * Run each agent in order, passing the prior agent's text output as the next prompt.
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
        $agents = array_slice($swarm->agents(), 0, $maxAgentExecutions);
        $steps = [];
        $input = $task;
        $mergedUsage = [];

        foreach ($agents as $agent) {
            if (hrtime(true) >= $deadlineMonotonic) {
                throw new SwarmTimeoutException('The swarm exceeded its configured timeout while running sequentially.');
            }

            $response = $agent->prompt($input);
            $output = (string) $response;

            $steps[] = new SwarmStep(
                agentClass: $agent::class,
                input: $input,
                output: $output,
            );

            $mergedUsage = $this->mergeUsage($mergedUsage, $this->usageFromResponse($response));

            $cache->put($contextKey, [
                'topology' => 'sequential',
                'last_output' => $output,
                'steps' => count($steps),
            ], $contextTtlSeconds);

            $input = $output;
        }

        return [
            'response' => new SwarmResponse(
                output: $input,
                steps: $steps,
                usage: $mergedUsage,
            ),
            'usage' => $mergedUsage,
        ];
    }

    /**
     * @param  array<string, int>  $accumulated
     * @return array<string, int>
     */
    protected function mergeUsage(array $accumulated, array $next): array
    {
        foreach ($next as $key => $value) {
            $accumulated[$key] = ($accumulated[$key] ?? 0) + $value;
        }

        return $accumulated;
    }

    /**
     * @return array<string, int>
     */
    protected function usageFromResponse(mixed $response): array
    {
        if ($response instanceof AgentResponse) {
            return $response->usage->toArray();
        }

        return [];
    }
}
