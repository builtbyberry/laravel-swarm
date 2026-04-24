<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;

class HierarchicalRunner
{
    public function run(SwarmExecutionState $state): SwarmResponse
    {
        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);

        if ($agents === []) {
            throw new SwarmException('Hierarchical swarms must define at least one agent.');
        }

        if (! method_exists($state->swarm, 'route')) {
            throw new SwarmException('Hierarchical swarms must define a route() method.');
        }

        /** @var Agent $coordinator */
        $coordinator = array_shift($agents);
        $steps = [];
        $mergedUsage = [];

        $coordinatorStep = $this->executeAgent(
            state: $state,
            agent: $coordinator,
            input: $state->context->input,
            index: 0,
        );

        $steps[] = $coordinatorStep;
        $mergedUsage = $this->mergeUsage($mergedUsage, (array) ($coordinatorStep->metadata['usage'] ?? []));

        /** @var array<int, array{agent?: Agent, agent_class?: class-string, input: string, metadata?: array<string, mixed>}> $routes */
        $routes = $state->swarm->route($coordinatorStep->output, $agents, $state->context);
        $remainingExecutions = max($state->maxAgentExecutions - 1, 0);
        $routes = array_slice($routes, 0, $remainingExecutions);
        $routedClasses = [];
        $lastOutput = $coordinatorStep->output;

        foreach ($routes as $offset => $instruction) {
            $agent = $this->resolveRoutedAgent($instruction, $agents);
            $routedClasses[] = $agent::class;

            $step = $this->executeAgent(
                state: $state,
                agent: $agent,
                input: (string) $instruction['input'],
                index: $offset + 1,
                metadata: is_array($instruction['metadata'] ?? null) ? $instruction['metadata'] : [],
            );

            $steps[] = $step;
            $lastOutput = $step->output;
            $mergedUsage = $this->mergeUsage($mergedUsage, (array) ($step->metadata['usage'] ?? []));
        }

        $state->context
            ->mergeData([
                'last_output' => $lastOutput,
                'steps' => count($steps),
            ])
            ->mergeMetadata([
                'topology' => $state->topology,
                'coordinator_agent_class' => $coordinator::class,
                'routed_agent_classes' => $routedClasses,
                'executed_steps' => count($steps),
            ]);

        $state->contextStore->put($state->context, $state->ttlSeconds);

        return new SwarmResponse(
            output: $lastOutput,
            steps: $steps,
            usage: $mergedUsage,
            context: $state->context,
            artifacts: $state->context->artifacts,
            metadata: [
                'run_id' => $state->context->runId,
                'topology' => $state->topology,
                'coordinator_agent_class' => $coordinator::class,
                'routed_agent_classes' => $routedClasses,
                'executed_steps' => count($steps),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function executeAgent(SwarmExecutionState $state, Agent $agent, string $input, int $index, array $metadata = []): SwarmStep
    {
        if (hrtime(true) >= $state->deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout while running hierarchically.');
        }

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
            metadata: array_merge(['index' => $index, 'usage' => $usage], $metadata),
            stepAgentClass: $agent::class,
        );
        $step = new SwarmStep(
            agentClass: $agent::class,
            input: $input,
            output: $output,
            artifacts: [$artifact],
            metadata: array_merge(['index' => $index, 'usage' => $usage], $metadata),
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

        return $step;
    }

    /**
     * @param  array{agent?: Agent, agent_class?: class-string, input: string, metadata?: array<string, mixed>}  $instruction
     * @param  array<int, Agent>  $agents
     */
    protected function resolveRoutedAgent(array $instruction, array $agents): Agent
    {
        if (($instruction['agent'] ?? null) instanceof Agent) {
            foreach ($agents as $agent) {
                if ($agent === $instruction['agent']) {
                    return $agent;
                }
            }

            $class = $instruction['agent']::class;

            throw new SwarmException("Hierarchical route references unknown agent class [{$class}]. Verify it is returned from agents().");
        }

        $class = $instruction['agent_class'] ?? null;

        foreach ($agents as $agent) {
            if ($class === $agent::class) {
                return $agent;
            }
        }

        $unknownClass = is_string($class) ? $class : 'unknown';

        throw new SwarmException("Hierarchical route references unknown agent class [{$unknownClass}]. Verify it is returned from agents().");
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
