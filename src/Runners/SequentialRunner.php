<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Generator;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;

class SequentialRunner
{
    public function run(SwarmExecutionState $state): SwarmResponse
    {
        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);
        $steps = [];
        $mergedUsage = [];

        foreach ($agents as $index => $agent) {
            if (hrtime(true) >= $state->deadlineMonotonic) {
                throw new SwarmTimeoutException('The swarm exceeded its configured timeout while running sequentially.');
            }

            $input = $state->context->prompt();
            $state->events->dispatch(new SwarmStepStarted(
                runId: $state->context->runId,
                swarmClass: $state->swarm::class,
                index: $index,
                agentClass: $agent::class,
                input: $input,
                metadata: $state->context->metadata,
            ));

            $startedAt = MonotonicTime::now();
            $response = $agent->prompt($input);
            $output = (string) $response;
            $usage = $this->usageFromResponse($response);
            $artifact = new SwarmArtifact(
                name: 'agent_output',
                content: $output,
                metadata: ['index' => $index, 'usage' => $usage],
                stepAgentClass: $agent::class,
            );

            $step = new SwarmStep(
                agentClass: $agent::class,
                input: $input,
                output: $output,
                artifacts: [$artifact],
                metadata: ['index' => $index, 'usage' => $usage],
            );

            $steps[] = $step;
            $mergedUsage = $this->mergeUsage($mergedUsage, $usage);

            $state->context
                ->mergeData([
                    'last_output' => $output,
                    'steps' => count($steps),
                ])
                ->mergeMetadata([
                    'topology' => $state->topology,
                    'last_agent' => $agent::class,
                ])
                ->addArtifact($artifact);

            $state->historyStore->recordStep($state->context->runId, $step, $state->ttlSeconds, $state->executionToken, $state->leaseSeconds);
            $state->contextStore->put($state->context, $state->ttlSeconds);
            $state->artifactRepository->storeMany($state->context->runId, [$artifact], $state->ttlSeconds);
            $state->events->dispatch(new SwarmStepCompleted(
                runId: $state->context->runId,
                swarmClass: $state->swarm::class,
                topology: $state->topology,
                index: $index,
                agentClass: $agent::class,
                input: $input,
                output: $output,
                durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
                metadata: $step->metadata,
                artifacts: $step->artifacts,
            ));
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

            $state->events->dispatch(new SwarmStepStarted(
                runId: $state->context->runId,
                swarmClass: $state->swarm::class,
                index: $index,
                agentClass: $agent::class,
                input: $input,
                metadata: $state->context->metadata,
            ));

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

                $artifact = new SwarmArtifact(
                    name: 'agent_output',
                    content: $output,
                    metadata: ['index' => $index],
                    stepAgentClass: $agent::class,
                );
                $step = new SwarmStep(
                    agentClass: $agent::class,
                    input: $input,
                    output: $output,
                    artifacts: [$artifact],
                    metadata: ['index' => $index],
                );

                $state->context
                    ->mergeData([
                        'last_output' => $output,
                        'steps' => $index + 1,
                    ])
                    ->mergeMetadata([
                        'topology' => $state->topology,
                        'last_agent' => $agent::class,
                    ])
                    ->addArtifact($artifact);

                $state->historyStore->recordStep($state->context->runId, $step, $state->ttlSeconds, $state->executionToken, $state->leaseSeconds);
                $state->artifactRepository->storeMany($state->context->runId, [$artifact], $state->ttlSeconds);
                $state->events->dispatch(new SwarmStepCompleted(
                    runId: $state->context->runId,
                    swarmClass: $state->swarm::class,
                    topology: $state->topology,
                    index: $index,
                    agentClass: $agent::class,
                    input: $input,
                    output: $output,
                    durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
                    metadata: $step->metadata,
                    artifacts: $step->artifacts,
                ));
            } else {
                $response = $agent->prompt($input);
                $output = (string) $response;
                $usage = $this->usageFromResponse($response);
                $mergedUsage = $this->mergeUsage($mergedUsage, $usage);
                $artifact = new SwarmArtifact(
                    name: 'agent_output',
                    content: $output,
                    metadata: ['index' => $index, 'usage' => $usage],
                    stepAgentClass: $agent::class,
                );
                $step = new SwarmStep(
                    agentClass: $agent::class,
                    input: $input,
                    output: $output,
                    artifacts: [$artifact],
                    metadata: ['index' => $index, 'usage' => $usage],
                );

                $state->context
                    ->mergeData([
                        'last_output' => $output,
                        'steps' => $index + 1,
                    ])
                    ->mergeMetadata([
                        'topology' => $state->topology,
                        'last_agent' => $agent::class,
                    ])
                    ->addArtifact($artifact);

                $state->historyStore->recordStep($state->context->runId, $step, $state->ttlSeconds, $state->executionToken, $state->leaseSeconds);
                $state->artifactRepository->storeMany($state->context->runId, [$artifact], $state->ttlSeconds);
                $state->events->dispatch(new SwarmStepCompleted(
                    runId: $state->context->runId,
                    swarmClass: $state->swarm::class,
                    topology: $state->topology,
                    index: $index,
                    agentClass: $agent::class,
                    input: $input,
                    output: $output,
                    durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
                    metadata: $step->metadata,
                    artifacts: $step->artifacts,
                ));
            }

            $state->contextStore->put($state->context, $state->ttlSeconds);

            yield ['event' => 'step', 'agent' => $agentName, 'status' => 'done'];
        }

        $state->context->mergeMetadata([
            'usage' => $mergedUsage,
        ]);
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
