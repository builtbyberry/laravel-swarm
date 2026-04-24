<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;

class SwarmStepRecorder
{
    public function __construct(
        protected SwarmCapture $capture,
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
        $stepMetadata = array_merge(
            $includeUsageInMetadata ? ['index' => $index, 'usage' => $usage] : ['index' => $index],
            $metadata,
        );

        $artifact = new SwarmArtifact(
            name: 'agent_output',
            content: $output,
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
                'topology' => $state->topology,
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

        if ($this->capture->capturesOutputs()) {
            $state->context->addArtifact($artifact);
        }

        $this->verifyOwnership($state);
        $state->historyStore->recordStep($state->context->runId, $this->capture->step($step), $state->ttlSeconds, $state->executionToken, $state->leaseSeconds);

        if ($storeContext) {
            $this->verifyOwnership($state);
            $state->contextStore->put($state->context, $state->ttlSeconds);
        }

        if ($storeArtifacts && $this->capture->capturesOutputs()) {
            $this->verifyOwnership($state);
            $state->artifactRepository->storeMany($state->context->runId, [$artifact], $state->ttlSeconds);
        }

        $this->verifyOwnership($state);
        $state->events->dispatch(new SwarmStepCompleted(
            runId: $state->context->runId,
            swarmClass: $state->swarm::class,
            topology: $state->topology,
            index: $index,
            agentClass: $agentClass,
            input: $this->capture->input($input),
            output: $this->capture->output($output),
            durationMs: $durationMs,
            metadata: $step->metadata,
            artifacts: $this->capture->artifacts($step->artifacts),
        ));

        return $step;
    }

    protected function verifyOwnership(SwarmExecutionState $state): void
    {
        if (is_callable($state->verifyOwnership)) {
            ($state->verifyOwnership)();
        }
    }
}
