<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Concerns\MergesAgentUsage;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\GuardrailParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Memory\AgentVisibleMemoryView;
use BuiltByBerry\LaravelSwarm\Memory\SnapshotToolCallNormalizer;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\AdHocSwarm;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentInvoker;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentSettingsAttempt;
use BuiltByBerry\LaravelSwarm\Support\NativeStepResultProjector;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

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
        protected NativeOutcomeValidator $outcomes,
        protected ParallelAgentResolver $agentResolver,
    ) {}

    public function run(SwarmExecutionState $state): SwarmResponse
    {
        if (hrtime(true) >= $state->deadlineMonotonic) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout before parallel execution began.');
        }

        $agents = array_slice($state->swarm->agents(), 0, $state->maxAgentExecutions);
        $input = $state->context->prompt();
        $this->ensureAgentsAreContainerResolvable($state->swarm);

        if ($state->swarm instanceof AdHocSwarm
            && (bool) $this->config->get('swarm.native_agent_settings.enabled', false)) {
            foreach (array_keys($agents) as $index) {
                if (! $state->context->hasNativeSettingsFor("parallel:{$index}")) {
                    throw new SwarmException('Ad-hoc parallel agents are reconstructed in worker processes, so live instance state cannot be preserved. Declare per-run native settings for every slot with RunContext::withAgentConfiguration(), or move the agents into a container-resolvable swarm class.');
                }
            }
        }

        $callbacks = [];
        $citationLimits = ['max_count' => (int) $this->config->get('swarm.citations.max_count', 256),
            'max_bytes' => (int) $this->config->get('swarm.citations.max_bytes', 262144)];
        $nativeResultLimits = Container::getInstance()->make(NativeStepResultProjector::class)->resolvedLimits();
        $snapshots = [];
        // Constant for the run; forwarded into each worker closure so the
        // ambient run context is reconstructable even when the concurrency
        // driver runs the closure in a separate process.
        $runId = $state->context->runId;
        $swarmClass = $state->swarm::class;
        $contextPayload = $state->context->toQueuePayload();
        $attemptIds = $state->nativeSettingsAttempt->ids();
        $adHoc = $state->swarm instanceof AdHocSwarm;
        foreach ($agents as $index => $agent) {
            $agentClass = $agent::class;
            $this->stepsRecorder->started($state, $index, $agentClass, $input);
            $snapshots[$index] = $this->snapshots->snapshot(
                $state->context->runId,
                $index,
                $this->view->present($state->swarm, $state->context, $agent),
            );

            $callbacks[$index] = function () use ($agentClass, $input, $runId, $swarmClass, $contextPayload, $index, $citationLimits, $nativeResultLimits, $attemptIds, $adHoc): array {
                $agent = Container::getInstance()->make(ParallelAgentResolver::class)
                    ->resolve($swarmClass, $agentClass, $index, $adHoc);

                $workerContext = RunContext::fromPayload($contextPayload, $runId);
                $attempt = new NativeAgentSettingsAttempt($attemptIds);
                ActiveRunContext::enter($runId, $swarmClass, $workerContext);

                try {
                    $startedAt = MonotonicTime::now();
                    $invocation = $workerContext->nativeInvocation("parallel:{$index}", $input, $attempt);
                    $response = NativeAgentInvoker::prompt($agent, $invocation);
                    Container::getInstance()->make(NativeOutcomeValidator::class)->validateResponse($response);

                    return [
                        'output' => (string) $response,
                        'citation_evidence' => NativeCitationEvidence::forConcurrentWorker($citationLimits)->response($response, $runId, $index, $agentClass)->toArray(),
                        'usage' => $response->usage->toArray(),
                        'class' => $agentClass,
                        'duration_ms' => MonotonicTime::elapsedMilliseconds($startedAt),
                        'tool_calls' => SnapshotToolCallNormalizer::fromResponse($response),
                        'native_settings_consumed' => $attempt->ids(),
                        'native_result' => NativeStepResultProjector::fromResolvedLimits($nativeResultLimits)->fromResponse($response)->toArray(),
                    ];
                } finally {
                    ActiveRunContext::exit();
                }
            };
        }

        $driver = $this->concurrency->driver();
        $results = $driver->run(ConcurrentAgentResult::wrapCallbacks($driver, $callbacks));
        /** @var array<int, array{output: string, citation_evidence: array<string, mixed>, usage: array<string, int|null>, class: string, duration_ms: int, tool_calls: array<int, array{name: string, arguments: array<string, mixed>, result: mixed, id: string|null, result_id: string|null}>, native_settings_consumed: list<string>, native_result: array<string, mixed>}> $results */
        $results = $this->outcomes->validateConcurrentResults($results);

        foreach ($results as $row) {
            $state->nativeSettingsAttempt->merge(is_array($row['native_settings_consumed'] ?? null) ? $row['native_settings_consumed'] : []);
        }

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
                citationEvidence: CitationEvidence::fromArray($row['citation_evidence']),
                nativeResult: NativeStepResult::fromArray($row['native_result']),
            );

            $steps[] = $step;
            $outputs[] = $row['output'];
            $mergedUsage = $this->mergeUsageReport($mergedUsage, $row['usage']);
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
            citationEvidence: CitationEvidence::combine(array_map(static fn ($step) => $step->citationEvidence, $steps)),
            usage: $mergedUsage,
            context: $state->context,
            artifacts: $state->context->artifacts,
            metadata: [
                'run_id' => $state->context->runId,
                'topology' => $state->topology->value,
            ],
        );
    }

    public function ensureAgentsAreContainerResolvable(Swarm $swarm): void
    {
        $this->agentResolver->ensureResolvable($swarm);
    }
}
