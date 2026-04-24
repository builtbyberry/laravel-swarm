<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Concurrency\ConcurrencyManager;
use Laravel\Ai\Responses\AgentResponse;

class ParallelRunner
{
    public function __construct(
        protected ConcurrencyManager $concurrency,
        protected SwarmStepRecorder $stepsRecorder,
    ) {}

    public function run(SwarmExecutionState $state): SwarmResponse
    {
        if (hrtime(true) >= $state->deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout before parallel execution began.');
        }

        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);
        $input = $state->context->prompt();

        $callbacks = [];
        foreach ($agents as $index => $agent) {
            $this->stepsRecorder->started($state, $index, $agent::class, $input);

            $callbacks[$index] = function () use ($agent, $input): array {
                $startedAt = MonotonicTime::now();
                $response = $agent->prompt($input);

                return [
                    'output' => (string) $response,
                    'usage' => $response instanceof AgentResponse ? $response->usage->toArray() : [],
                    'class' => $agent::class,
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
            $row = $results[$index] ?? ['output' => '', 'usage' => [], 'class' => $agent::class, 'duration_ms' => 1];
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

        $state->contextStore->put($state->context, $state->ttlSeconds);
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
