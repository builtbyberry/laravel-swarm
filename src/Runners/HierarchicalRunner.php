<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalFinishNode;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalParallelNode;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalWorkerNode;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;

class HierarchicalRunner
{
    public function __construct(
        protected HierarchicalRoutePlanner $planner,
        protected ConcurrencyManager $concurrency,
        protected SwarmStepRecorder $stepsRecorder,
        protected SwarmCapture $capture,
    ) {}

    public function run(SwarmExecutionState $state): SwarmResponse
    {
        $agents = $state->swarm->agents();

        if ($agents === []) {
            throw new SwarmException('Hierarchical swarms must define at least one agent.');
        }

        /** @var Agent $coordinator */
        $coordinator = array_shift($agents);
        $this->planner->assertCoordinatorCanPlan($coordinator);
        $this->ensureUniqueWorkerClasses($state->swarm::class, $agents);
        $workerMap = $this->workerMap($agents);

        $steps = [];
        $mergedUsage = [];
        $executedNodeIds = [];
        $executedAgentClasses = [];
        $parallelGroups = [];
        $nodeOutputs = [];
        $nextIndex = 1;

        $coordinatorStep = $this->executeAgent(
            state: $state,
            agent: $coordinator,
            input: $state->context->input,
            index: 0,
            metadata: ['node_role' => 'coordinator'],
        );

        $steps[] = $coordinatorStep;
        $mergedUsage = $this->mergeUsage($mergedUsage, (array) ($coordinatorStep->metadata['usage'] ?? []));

        $plan = $this->planner->fromCoordinatorOutput($coordinator, $agents, $coordinatorStep->output, $state->swarm::class);
        $this->ensurePlanWithinExecutionBudget($state, $plan);

        $finalOutput = $this->executePlan(
            state: $state,
            plan: $plan,
            workerMap: $workerMap,
            steps: $steps,
            mergedUsage: $mergedUsage,
            executedNodeIds: $executedNodeIds,
            executedAgentClasses: $executedAgentClasses,
            parallelGroups: $parallelGroups,
            nodeOutputs: $nodeOutputs,
            nextIndex: $nextIndex,
        );

        $state->context
            ->mergeData([
                'last_output' => $finalOutput,
                'steps' => count($steps),
                'hierarchical_node_outputs' => $nodeOutputs,
            ])
            ->mergeMetadata([
                'topology' => $state->topology,
                'coordinator_agent_class' => $coordinator::class,
                'route_plan_start' => $plan->startAt,
                'executed_node_ids' => $executedNodeIds,
                'executed_agent_classes' => $executedAgentClasses,
                'parallel_groups' => $parallelGroups,
                'executed_steps' => count($steps),
                'execution_mode' => $state->executionMode,
            ]);

        $state->contextStore->put($this->capture->activeContext($state->context), $state->ttlSeconds);

        return new SwarmResponse(
            output: $finalOutput,
            steps: $steps,
            usage: $mergedUsage,
            context: $state->context,
            artifacts: $state->context->artifacts,
            metadata: [
                'run_id' => $state->context->runId,
                'topology' => $state->topology,
                'coordinator_agent_class' => $coordinator::class,
                'route_plan_start' => $plan->startAt,
                'executed_node_ids' => $executedNodeIds,
                'executed_agent_classes' => $executedAgentClasses,
                'parallel_groups' => $parallelGroups,
                'executed_steps' => count($steps),
                'execution_mode' => $state->executionMode,
            ],
        );
    }

    /**
     * @param  array<class-string, Agent>  $workerMap
     * @param  array<int, SwarmStep>  $steps
     * @param  array<string, int>  $mergedUsage
     * @param  array<int, string>  $executedNodeIds
     * @param  array<int, string>  $executedAgentClasses
     * @param  array<int, array{node_id: string, branches: array<int, string>}>  $parallelGroups
     * @param  array<string, string>  $nodeOutputs
     */
    protected function executePlan(
        SwarmExecutionState $state,
        HierarchicalRoutePlan $plan,
        array $workerMap,
        array &$steps,
        array &$mergedUsage,
        array &$executedNodeIds,
        array &$executedAgentClasses,
        array &$parallelGroups,
        array &$nodeOutputs,
        int &$nextIndex,
    ): string {
        $currentNodeId = $plan->startAt;
        $lastOutput = null;

        while ($currentNodeId !== null) {
            $node = $plan->node($currentNodeId);

            $executedNodeIds[] = $node->id;

            if ($node instanceof HierarchicalWorkerNode) {
                $step = $this->executeAgent(
                    state: $state,
                    agent: $workerMap[$node->agentClass],
                    input: $this->composePrompt($node->prompt, $node->withOutputs, $nodeOutputs, $node->id),
                    index: $nextIndex,
                    metadata: array_merge($node->metadata, ['node_id' => $node->id]),
                );

                $steps[] = $step;
                $mergedUsage = $this->mergeUsage($mergedUsage, (array) ($step->metadata['usage'] ?? []));
                $nodeOutputs[$node->id] = $step->output;
                $executedAgentClasses[] = $step->agentClass;
                $lastOutput = $step->output;
                $nextIndex++;
                $currentNodeId = $node->next;

                continue;
            }

            if ($node instanceof HierarchicalParallelNode) {
                $parallelGroups[] = ['node_id' => $node->id, 'branches' => $node->branches];

                if ($state->executionMode === 'queue') {
                    foreach ($node->branches as $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $plan->node($branchNodeId);
                        $step = $this->executeAgent(
                            state: $state,
                            agent: $workerMap[$branch->agentClass],
                            input: $this->composePrompt($branch->prompt, $branch->withOutputs, $nodeOutputs, $branch->id),
                            index: $nextIndex,
                            metadata: array_merge($branch->metadata, [
                                'node_id' => $branch->id,
                                'parent_parallel_node_id' => $node->id,
                            ]),
                        );

                        $steps[] = $step;
                        $mergedUsage = $this->mergeUsage($mergedUsage, (array) ($step->metadata['usage'] ?? []));
                        $nodeOutputs[$branch->id] = $step->output;
                        $executedNodeIds[] = $branch->id;
                        $executedAgentClasses[] = $step->agentClass;
                        $lastOutput = $step->output;
                        $nextIndex++;
                    }
                } else {
                    $branchDefinitions = [];
                    $callbacks = [];

                    foreach ($node->branches as $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $plan->node($branchNodeId);
                        $this->resolveParallelWorker($state->swarm::class, $branch->agentClass);
                        $input = $this->composePrompt($branch->prompt, $branch->withOutputs, $nodeOutputs, $branch->id);

                        $this->stepsRecorder->started($state, $nextIndex + count($branchDefinitions), $branch->agentClass, $input);

                        $branchDefinitions[$branchNodeId] = [
                            'node' => $branch,
                            'input' => $input,
                            'index' => $nextIndex + count($branchDefinitions),
                        ];

                        $agentClass = $branch->agentClass;
                        $callbacks[$branchNodeId] = function () use ($agentClass, $input): array {
                            $worker = Container::getInstance()->make($agentClass);

                            if (! $worker instanceof Agent) {
                                throw new SwarmException("Hierarchical parallel worker [{$agentClass}] must resolve to a Laravel AI agent.");
                            }

                            $startedAt = MonotonicTime::now();
                            $response = $worker->prompt($input);

                            return [
                                'output' => (string) $response,
                                'usage' => $response->usage->toArray(),
                                'duration_ms' => MonotonicTime::elapsedMilliseconds($startedAt),
                            ];
                        };
                    }

                    /** @var array<string, array{output: string, usage: array<string, int>, duration_ms: int}> $results */
                    $results = $this->concurrency->driver()->run($callbacks);

                    foreach ($node->branches as $branchNodeId) {
                        /** @var HierarchicalWorkerNode $branch */
                        $branch = $branchDefinitions[$branchNodeId]['node'];
                        $input = $branchDefinitions[$branchNodeId]['input'];
                        $index = $branchDefinitions[$branchNodeId]['index'];

                        if (! array_key_exists($branchNodeId, $results)) {
                            throw new SwarmException($state->swarm::class.": hierarchical parallel execution did not return a result for branch node [{$branchNodeId}].");
                        }

                        $row = $results[$branchNodeId];

                        $step = $this->stepsRecorder->completed(
                            state: $state,
                            index: $index,
                            agentClass: $branch->agentClass,
                            input: $input,
                            output: $row['output'],
                            usage: $row['usage'],
                            durationMs: $row['duration_ms'],
                            metadata: array_merge($branch->metadata, [
                                'index' => $index,
                                'usage' => $row['usage'],
                                'node_id' => $branch->id,
                                'parent_parallel_node_id' => $node->id,
                            ]),
                            updateContext: false,
                            storeContext: false,
                            includeUsageInMetadata: false,
                        );

                        $steps[] = $step;
                        $mergedUsage = $this->mergeUsage($mergedUsage, $row['usage']);
                        $nodeOutputs[$branch->id] = $step->output;
                        $executedNodeIds[] = $branch->id;
                        $executedAgentClasses[] = $step->agentClass;
                        $lastOutput = $step->output;
                    }

                    $state->contextStore->put($this->capture->activeContext($state->context), $state->ttlSeconds);
                    $nextIndex += count($node->branches);
                }

                $currentNodeId = $node->next;

                continue;
            }

            /** @var HierarchicalFinishNode $node */
            return $node->output ?? $this->resolveOutputFromNode($node, $nodeOutputs);
        }

        return $lastOutput ?? '';
    }

    protected function ensurePlanWithinExecutionBudget(SwarmExecutionState $state, HierarchicalRoutePlan $plan): void
    {
        $requiredExecutions = 1 + $plan->reachableWorkerCount();

        if ($requiredExecutions <= $state->maxAgentExecutions) {
            return;
        }

        throw new SwarmException(sprintf(
            "%s: hierarchical route plan requires %d agent executions but the swarm allows %d. Increase #[MaxAgentSteps] or reduce the plan's worker nodes.",
            $state->swarm::class,
            $requiredExecutions,
            $state->maxAgentExecutions,
        ));
    }

    protected function resolveOutputFromNode(HierarchicalFinishNode $node, array $nodeOutputs): string
    {
        $sourceNodeId = $node->outputFrom;

        if ($sourceNodeId === null) {
            throw new SwarmException("Hierarchical finish node [{$node->id}] did not resolve a final output.");
        }

        if (! array_key_exists($sourceNodeId, $nodeOutputs)) {
            throw new SwarmException("Hierarchical finish node [{$node->id}] cannot resolve output from unexecuted node [{$sourceNodeId}].");
        }

        return $nodeOutputs[$sourceNodeId];
    }

    /**
     * @param  array<string, string>  $withOutputs
     * @param  array<string, string>  $nodeOutputs
     */
    protected function composePrompt(string $prompt, array $withOutputs, array $nodeOutputs, string $nodeId): string
    {
        if ($withOutputs === []) {
            return $prompt;
        }

        $sections = [];

        foreach ($withOutputs as $alias => $sourceNodeId) {
            if (! array_key_exists($sourceNodeId, $nodeOutputs)) {
                throw new SwarmException("Hierarchical worker node [{$nodeId}] cannot resolve named output [{$alias}] from unexecuted node [{$sourceNodeId}].");
            }

            $sections[] = "[{$alias}]\n".$nodeOutputs[$sourceNodeId];
        }

        return rtrim($prompt)."\n\nNamed outputs:\n".implode("\n\n", $sections);
    }

    /**
     * @param  array<int, Agent>  $workers
     * @return array<class-string, Agent>
     */
    protected function workerMap(array $workers): array
    {
        $map = [];

        foreach ($workers as $worker) {
            $map[$worker::class] = $worker;
        }

        return $map;
    }

    public function ensureUniqueWorkerClassesForSwarm(Swarm $swarm): void
    {
        $agents = $swarm->agents();

        if ($agents === []) {
            return;
        }

        array_shift($agents);
        $this->ensureUniqueWorkerClasses($swarm::class, $agents);
    }

    /**
     * @param  array<int, Agent>  $workers
     */
    protected function ensureUniqueWorkerClasses(string $swarmClass, array $workers): void
    {
        $seen = [];

        foreach ($workers as $worker) {
            if (isset($seen[$worker::class])) {
                throw new SwarmException($swarmClass.': agents() contains duplicate agent class '.$worker::class.'. Hierarchical worker classes must be unique.');
            }

            $seen[$worker::class] = true;
        }
    }

    /**
     * @param  class-string<Agent>  $agentClass
     */
    protected function resolveParallelWorker(string $swarmClass, string $agentClass): Agent
    {
        try {
            $worker = Container::getInstance()->make($agentClass);
        } catch (BindingResolutionException $exception) {
            throw new SwarmException(
                "{$swarmClass}: hierarchical parallel worker [{$agentClass}] must be container-resolvable because Laravel Concurrency serializes worker callbacks.",
                previous: $exception,
            );
        }

        if (! $worker instanceof Agent) {
            throw new SwarmException("{$swarmClass}: hierarchical parallel worker [{$agentClass}] must resolve to a Laravel AI agent.");
        }

        return $worker;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function executeAgent(SwarmExecutionState $state, Agent $agent, string $input, int $index, array $metadata = []): SwarmStep
    {
        if (hrtime(true) >= $state->deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout while running hierarchically.');
        }

        $this->stepsRecorder->started($state, $index, $agent::class, $input);

        $startedAt = MonotonicTime::now();
        $response = $agent->prompt($input);
        $output = (string) $response;
        $usage = $this->usageFromResponse($response);

        return $this->stepsRecorder->completed(
            state: $state,
            index: $index,
            agentClass: $agent::class,
            input: $input,
            output: $output,
            usage: $usage,
            durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
            metadata: $metadata,
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
