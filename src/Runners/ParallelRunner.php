<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Laravel\Ai\Contracts\Agent;

class ParallelRunner
{
    public function __construct(
        protected ConcurrencyManager $concurrency,
        protected SwarmStepRecorder $stepsRecorder,
        protected SwarmCapture $capture,
    ) {}

    public function run(SwarmExecutionState $state): SwarmResponse
    {
        if (hrtime(true) >= $state->deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout before parallel execution began.');
        }

        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);
        $input = $state->context->prompt();
        $this->ensureAgentsAreContainerResolvable($agents, $state->swarm::class);

        $callbacks = [];
        foreach ($agents as $index => $agent) {
            $agentClass = $agent::class;
            $this->stepsRecorder->started($state, $index, $agentClass, $input);

            $callbacks[$index] = function () use ($agentClass, $input): array {
                $agent = Container::getInstance()->make($agentClass);

                if (! $agent instanceof Agent) {
                    throw new SwarmException("Parallel swarm agent [{$agentClass}] must resolve to a Laravel AI agent.");
                }

                $startedAt = MonotonicTime::now();
                $response = $agent->prompt($input);

                return [
                    'output' => (string) $response,
                    'usage' => $response->usage->toArray(),
                    'class' => $agentClass,
                    'duration_ms' => MonotonicTime::elapsedMilliseconds($startedAt),
                ];
            };
        }

        /** @var array<int, array{output: string, usage: array<string, int>, class: string, duration_ms: int}> $results */
        $results = $this->concurrency->driver()->run($callbacks);

        if (hrtime(true) >= $state->deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout after parallel execution.');
        }

        $steps = [];
        $mergedUsage = [];
        $outputs = [];

        foreach ($agents as $index => $agent) {
            if (! array_key_exists($index, $results)) {
                throw new SwarmException($state->swarm::class.": parallel execution did not return a result for agent index [{$index}].");
            }

            $row = $results[$index];
            $step = $this->stepsRecorder->completed(
                state: $state,
                index: $index,
                agentClass: $row['class'],
                input: $input,
                output: $row['output'],
                usage: $row['usage'],
                durationMs: $row['duration_ms'],
                updateContext: false,
                storeContext: false,
                storeArtifacts: false,
            );

            $steps[] = $step;
            $outputs[] = $row['output'];
            $mergedUsage = $this->mergeUsage($mergedUsage, $row['usage']);
        }

        $combined = implode("\n\n", $outputs);

        $state->context
            ->mergeData([
                'last_output' => $combined,
                'steps' => count($steps),
            ])
            ->mergeMetadata([
                'topology' => $state->topology,
            ]);

        $state->contextStore->put($this->capture->activeContext($state->context), $state->ttlSeconds);
        $state->artifactRepository->storeMany($state->context->runId, $state->context->artifacts, $state->ttlSeconds);

        return new SwarmResponse(
            output: $combined,
            steps: $steps,
            usage: $mergedUsage,
            context: $state->context,
            artifacts: $state->context->artifacts,
            metadata: [
                'run_id' => $state->context->runId,
                'topology' => $state->topology,
            ],
        );
    }

    /**
     * @param  array<int, object>  $agents
     */
    public function ensureAgentsAreContainerResolvable(array $agents, string $swarmClass): void
    {
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
