<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\RecordsCitationSteps;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Memory\SwarmMemoryKeys;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\NativeStepResultProjector;
use BuiltByBerry\LaravelSwarm\Support\PayloadLimitResult;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Laravel\Ai\Responses\AgentResponse;

/**
 * @internal
 */
class SwarmStepRecorder
{
    public function __construct(
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
        protected SwarmAuditDispatcher $audit,
        protected ConfigRepository $config,
        protected StepEvidenceStorageReadiness $evidenceStorage,
        protected NativeStepResultProjector $nativeResults,
    ) {}

    public function nativeResult(AgentResponse $response): NativeStepResult
    {
        return $this->nativeResults->fromResponse($response);
    }

    public function started(SwarmExecutionState $state, int $index, string $agentClass, string $input): void
    {
        $this->evidenceStorage->check(
            durable: $state->executionMode === ExecutionMode::Durable || $state->queueHierarchicalParallelCoordination === 'multi_worker',
            checkpoints: $state->executionMode === ExecutionMode::Stream,
        );
        $state->events->dispatch(new SwarmStepStarted(
            runId: $state->context->runId,
            swarmClass: $state->swarm::class,
            index: $index,
            agentClass: $agentClass,
            input: $this->capture->applyInput($input, $state->context),
            metadata: $state->context->metadata,
            topology: $state->topology->value,
            executionMode: $state->executionMode->value,
        ));
        $this->audit->emit('step.started', [
            'run_id' => $state->context->runId,
            'parent_run_id' => $state->context->metadata['parent_run_id'] ?? null,
            'swarm_class' => $state->swarm::class,
            'topology' => $state->topology->value,
            'execution_mode' => $state->executionMode->value,
            'step_index' => $index,
            'agent_class' => $agentClass,
            'status' => 'started',
        ]);
    }

    /**
     * @param  array<string, int|null>  $usage
     * @param  array<string, mixed>  $metadata
     * @param  array<string, int|null>|null  $contextUsage
     */
    public function completed(
        SwarmExecutionState $state,
        int $index,
        string $agentClass,
        string $input,
        string $output,
        array $usage,
        int $durationMs,
        array $metadata = [],
        bool $updateContext = true,
        bool $storeContext = true,
        bool $storeArtifacts = true,
        bool $includeUsageInMetadata = true,
        ?array $contextUsage = null,
        ?CitationEvidence $citationEvidence = null,
        ?NativeStepResult $nativeResult = null,
    ): SwarmStep {
        $limitedOutput = $this->capture->capturesOutputs()
            ? $this->limits->output($output)
            : new PayloadLimitResult($this->capture->output($output));

        // Skip-aware value for emitted/persisted output: Full keeps the
        // (possibly truncated) value, Redact yields REDACTED, Skip yields null.
        $capturedOutput = $this->capture->applyOutput($limitedOutput->value, $state->context);

        $stepMetadata = array_merge(
            $includeUsageInMetadata ? ['index' => $index, 'usage' => $usage] : ['index' => $index],
            $metadata,
            $limitedOutput->metadata,
        );

        $artifact = new SwarmArtifact(
            name: 'agent_output',
            content: $limitedOutput->value,
            metadata: $stepMetadata,
            stepAgentClass: $agentClass,
        );

        $step = new SwarmStep(
            agentClass: $agentClass,
            input: $input,
            output: $output,
            artifacts: [$artifact],
            metadata: $stepMetadata,
            citationEvidence: $citationEvidence,
            nativeResult: $nativeResult,
        );

        if ($updateContext) {
            $contextMetadata = [
                'topology' => $state->topology->value,
                'last_agent' => $agentClass,
            ];

            if ($contextUsage !== null) {
                $contextMetadata['usage'] = $contextUsage;
            }

            $state->context
                ->mergeData([
                    'last_output' => $output,
                    'steps' => $index + 1,
                ])
                ->mergeMetadata($contextMetadata);
        }

        // Persist this step's output to Run scope under a reserved key so the
        // run accumulates a turn-by-turn record. Written unconditionally of
        // $updateContext (the parallel runner records steps with
        // updateContext: false, yet each branch's output must still be
        // captured) and gated only by the capture-step-output config. The
        // write flows through the same CapturePolicy / persistence / retention
        // path as any Run-scoped entry; DefaultPropagationPolicy hides these
        // keys, so non-trait agents observe no change.
        if ($this->config->get('swarm.memory.capture_step_output', false)) {
            $state->context->mergeData([
                SwarmMemoryKeys::stepOutput($index) => $output,
            ]);
        }

        if ($this->capture->capturesArtifacts()) {
            $state->context->addArtifact($artifact);
        }

        // Build the capture-shaped step before handing it to the configured
        // history store. Built-in stores then serialize the versioned envelope;
        // custom stores own their persistence and rehydration behavior.
        $this->verifyOwnership($state);
        $historyStep = new SwarmStep(
            agentClass: $agentClass,
            input: $input,
            output: $limitedOutput->value,
            artifacts: [$artifact],
            metadata: $stepMetadata,
            citationEvidence: $this->capture->citationEvidence($citationEvidence ?? new CitationEvidence, $state->context),
            nativeResult: $this->capture->nativeResult($nativeResult, $state->context),
        );
        if ($state->historyStore instanceof RecordsCitationSteps) {
            $state->historyStore->recordStepWithContext($state->context->runId, $historyStep, $state->ttlSeconds,
                $state->executionToken, $state->leaseSeconds, $state->context);
        } else {
            $state->historyStore->recordStep($state->context->runId, $historyStep, $state->ttlSeconds, $state->executionToken, $state->leaseSeconds);
        }

        if ($storeContext) {
            $this->verifyOwnership($state);
            $state->contextStore->put($this->capture->activeContext($state->context), $state->ttlSeconds);
        }

        if ($storeArtifacts && $this->capture->capturesArtifacts()) {
            $this->verifyOwnership($state);
            $state->artifactRepository->storeMany($state->context->runId, [$artifact], $state->ttlSeconds);
        }

        $this->verifyOwnership($state);
        $state->events->dispatch(new SwarmStepCompleted(
            runId: $state->context->runId,
            swarmClass: $state->swarm::class,
            topology: $state->topology->value,
            index: $index,
            agentClass: $agentClass,
            input: $this->capture->applyInput($input, $state->context),
            output: $capturedOutput,
            durationMs: $durationMs,
            metadata: $stepMetadata,
            artifacts: $this->capture->artifacts($step->artifacts),
            executionMode: $state->executionMode->value,
            nativeResult: $this->capture->nativeResult($nativeResult, $state->context),
        ));
        $this->audit->emit('step.completed', [
            'run_id' => $state->context->runId,
            'parent_run_id' => $state->context->metadata['parent_run_id'] ?? null,
            'swarm_class' => $state->swarm::class,
            'topology' => $state->topology->value,
            'execution_mode' => $state->executionMode->value,
            'step_index' => $index,
            'agent_class' => $agentClass,
            'duration_ms' => $durationMs,
            'status' => 'completed',
            ...$this->audit->metadata($stepMetadata),
        ]);

        return $step;
    }

    protected function verifyOwnership(SwarmExecutionState $state): void
    {
        if (is_callable($state->verifyOwnership)) {
            ($state->verifyOwnership)();
        }
    }
}
