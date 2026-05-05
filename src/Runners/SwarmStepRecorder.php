<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\PayloadLimitResult;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;

class SwarmStepRecorder
{
    public function __construct(
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
        protected SwarmAuditDispatcher $audit,
    ) {}

    public function started(SwarmExecutionState $state, int $index, string $agentClass, string $input): void
    {
        $state->events->dispatch(new SwarmStepStarted(
            runId: $state->context->runId,
            swarmClass: $state->swarm::class,
            index: $index,
            agentClass: $agentClass,
            input: $this->capture->input($input),
            metadata: $state->context->metadata,
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
     * @param  array<string, int>  $usage
     * @param  array<string, mixed>  $metadata
     * @param  array<string, int>|null  $contextUsage
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
    ): SwarmStep {
        $limitedOutput = $this->capture->capturesOutputs()
            ? $this->limits->output($output)
            : new PayloadLimitResult($this->capture->output($output));

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

        if ($this->capture->capturesArtifacts()) {
            $state->context->addArtifact($artifact);
        }

        $this->verifyOwnership($state);
        $state->historyStore->recordStep($state->context->runId, $this->capture->step(new SwarmStep(
            agentClass: $agentClass,
            input: $input,
            output: $limitedOutput->value,
            artifacts: [$artifact],
            metadata: $stepMetadata,
        )), $state->ttlSeconds, $state->executionToken, $state->leaseSeconds);

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
            input: $this->capture->input($input),
            output: $limitedOutput->value,
            durationMs: $durationMs,
            metadata: $stepMetadata,
            artifacts: $this->capture->artifacts($step->artifacts),
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
            'metadata' => $stepMetadata,
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
