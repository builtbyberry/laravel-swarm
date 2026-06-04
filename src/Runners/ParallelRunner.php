<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Concerns\MergesAgentUsage;
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Enums\GuardrailParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Memory\AgentVisibleMemoryView;
use BuiltByBerry\LaravelSwarm\Memory\SnapshotToolCallNormalizer;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\BindingResolutionException;

/**
 * @internal
 */
class ParallelRunner
{
    use MergesAgentUsage;

    public function __construct(
        protected ConcurrencyManager $concurrency,
        protected SwarmStepRecorder $stepsRecorder,
        protected SwarmCapture $capture,
        protected SwarmGuardrailRunner $guardrails,
        protected ConfigRepository $config,
        protected SnapshotsMemory $snapshots,
        protected AgentVisibleMemoryView $view,
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
        $snapshots = [];
        // Constant for the run; forwarded into each worker closure so the
        // ambient run context is reconstructable even when the concurrency
        // driver runs the closure in a separate process.
        $runId = $state->context->runId;
        $swarmClass = $state->swarm::class;
        $contextPayload = $state->context->toQueuePayload();
        foreach ($agents as $index => $agent) {
            $agentClass = $agent::class;
            $this->stepsRecorder->started($state, $index, $agentClass, $input);
            $snapshots[$index] = $this->snapshots->snapshot(
                $state->context->runId,
                $index,
                $this->view->present($state->swarm, $state->context, $agent),
            );

            $callbacks[$index] = function () use ($agentClass, $input, $runId, $swarmClass, $contextPayload): array {
                $agent = Container::getInstance()->make($agentClass);

                if (! $agent instanceof Agent) {
                    throw new SwarmException("Parallel swarm agent [{$agentClass}] must resolve to a Laravel AI agent.");
                }

                ActiveRunContext::enter($runId, $swarmClass, RunContext::fromPayload($contextPayload, $runId));

                try {
                    $startedAt = MonotonicTime::now();
                    $response = $agent->prompt($input);

                    return [
                        'output' => (string) $response,
                        'usage' => $response->usage->toArray(),
                        'class' => $agentClass,
                        'duration_ms' => MonotonicTime::elapsedMilliseconds($startedAt),
                        'tool_calls' => SnapshotToolCallNormalizer::fromResponse($response),
                    ];
                } finally {
                    ActiveRunContext::exit();
                }
            };
        }

        /** @var array<int, array{output: string, usage: array<string, int>, class: string, duration_ms: int, tool_calls: array<int, array{name: string, arguments: array<string, mixed>, result: mixed, id: string|null, result_id: string|null}>}> $results */
        $results = $this->concurrency->driver()->run($callbacks);

        foreach ($results as $rowIndex => $rowData) {
            if (! isset($snapshots[$rowIndex])) {
                continue;
            }

            $snapshot = $snapshots[$rowIndex];
            foreach ($rowData['tool_calls'] ?? [] as $toolCall) {
                $snapshot = $this->snapshots->appendToolCall($snapshot, $toolCall);
            }
            $snapshots[$rowIndex] = $snapshot;
        }

        if (hrtime(true) >= $state->deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout after parallel execution.');
        }

        $policy = GuardrailParallelFailurePolicy::tryFrom((string) $this->config->get(
            'swarm.guardrails.parallel_failure_policy',
            GuardrailParallelFailurePolicy::Existing->value,
        )) ?? GuardrailParallelFailurePolicy::Existing;

        if ($policy === GuardrailParallelFailurePolicy::BatchValidateBeforeRecord) {
            foreach ($agents as $index => $agent) {
                if (! array_key_exists($index, $results)) {
                    throw new SwarmException($state->swarm::class.": parallel execution did not return a result for agent index [{$index}].");
                }

                $row = $results[$index];
                $this->guardrails->validateStep(
                    $state->swarm,
                    GuardrailStepContext::fromState($state, $index, $row['class'], $input, $row['output'], []),
                    $state->context,
                );
            }
        }

        $steps = [];
        $mergedUsage = [];
        $outputs = [];

        foreach ($agents as $index => $agent) {
            if (! array_key_exists($index, $results)) {
                throw new SwarmException($state->swarm::class.": parallel execution did not return a result for agent index [{$index}].");
            }

            $row = $results[$index];

            if ($policy === GuardrailParallelFailurePolicy::Existing) {
                $this->guardrails->validateStep(
                    $state->swarm,
                    GuardrailStepContext::fromState($state, $index, $row['class'], $input, $row['output'], []),
                    $state->context,
                );
            }

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
                'topology' => $state->topology->value,
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
                'topology' => $state->topology->value,
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
}
