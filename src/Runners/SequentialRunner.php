<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Generator;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;

class SequentialRunner
{
    public function __construct(
        protected SwarmStepRecorder $steps,
    ) {}

    public function run(SwarmExecutionState $state): SwarmResponse
    {
        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);
        $steps = [];
        $mergedUsage = [];

        foreach ($agents as $index => $agent) {
            if (hrtime(true) >= $state->deadlineMonotonic) {
                throw new SwarmTimeoutException('The swarm exceeded its configured timeout while running sequentially.');
            }

            $step = $this->runSingleStep($state, $index);

            $steps[] = $step;
            $mergedUsage = $this->mergeUsage($mergedUsage, is_array($step->metadata['usage'] ?? null) ? $step->metadata['usage'] : []);
        }

        return new SwarmResponse(
            output: (string) ($state->context->data['last_output'] ?? $state->context->input),
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
     * @return Generator<int, array<string, string>, mixed, void>
     */
    public function stream(SwarmExecutionState $state): Generator
    {
        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);
        $lastIndex = count($agents) - 1;
        $mergedUsage = [];

        foreach ($agents as $index => $agent) {
            if (hrtime(true) >= $state->deadlineMonotonic) {
                throw new SwarmTimeoutException('The swarm exceeded its configured timeout while streaming sequentially.');
            }

            $input = $state->context->prompt();
            $agentName = class_basename($agent::class);

            $this->steps->started($state, $index, $agent::class, $input);

            yield ['event' => 'step', 'agent' => $agentName, 'status' => 'running'];

            $startedAt = MonotonicTime::now();

            if ($index === $lastIndex) {
                $stream = $agent->stream($input);
                $output = '';

                foreach ($stream as $event) {
                    if ($event instanceof TextDelta) {
                        $output .= $event->delta;
                        yield ['event' => 'token', 'token' => $event->delta];
                    }
                }

                $this->steps->completed(
                    state: $state,
                    index: $index,
                    agentClass: $agent::class,
                    input: $input,
                    output: $output,
                    usage: [],
                    durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
                    includeUsageInMetadata: false,
                );
            } else {
                $response = $agent->prompt($input);
                $output = (string) $response;
                $usage = $this->usageFromResponse($response);
                $mergedUsage = $this->mergeUsage($mergedUsage, $usage);

                $this->steps->completed(
                    state: $state,
                    index: $index,
                    agentClass: $agent::class,
                    input: $input,
                    output: $output,
                    usage: $usage,
                    durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
                );
            }

            yield ['event' => 'step', 'agent' => $agentName, 'status' => 'done'];
        }

        $state->context->mergeMetadata([
            'usage' => $mergedUsage,
        ]);
    }

    public function runSingleStep(SwarmExecutionState $state, int $index): SwarmStep
    {
        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);
        $agent = $agents[$index] ?? null;

        if ($agent === null) {
            throw new SwarmTimeoutException("No sequential swarm agent exists at step index [{$index}].");
        }

        if (hrtime(true) >= $state->deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout while running sequentially.');
        }

        $input = $state->context->prompt();
        $this->steps->started($state, $index, $agent::class, $input);

        $startedAt = MonotonicTime::now();
        $response = $agent->prompt($input);
        $output = (string) $response;
        $usage = $this->usageFromResponse($response);
        $mergedUsage = $this->mergeUsage(
            is_array($state->context->metadata['usage'] ?? null) ? $state->context->metadata['usage'] : [],
            $usage,
        );

        return $this->steps->completed(
            state: $state,
            index: $index,
            agentClass: $agent::class,
            input: $input,
            output: $output,
            usage: $usage,
            durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
            contextUsage: $mergedUsage,
        );
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
