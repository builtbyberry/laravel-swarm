<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class SwarmStepRecorder
{
    protected const REDACTED = '[redacted]';

    public function __construct(
        protected ConfigRepository $config,
    ) {}

    public function started(SwarmExecutionState $state, int $index, string $agentClass, string $input): void
    {
        $state->events->dispatch(new SwarmStepStarted(
            runId: $state->context->runId,
            swarmClass: $state->swarm::class,
            index: $index,
            agentClass: $agentClass,
            input: $this->capturedInput($input),
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

        if ($this->capturesOutputs()) {
            $state->context->addArtifact($artifact);
        }

        $this->verifyOwnership($state);
        $state->historyStore->recordStep($state->context->runId, $this->capturedStep($step), $state->ttlSeconds, $state->executionToken, $state->leaseSeconds);

        if ($storeContext) {
            $this->verifyOwnership($state);
            $state->contextStore->put($state->context, $state->ttlSeconds);
        }

        if ($storeArtifacts && $this->capturesOutputs()) {
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
            input: $this->capturedInput($input),
            output: $this->capturedOutput($output),
            durationMs: $durationMs,
            metadata: $step->metadata,
            artifacts: $this->capturesOutputs() ? $step->artifacts : [],
        ));

        return $step;
    }

    protected function capturedStep(SwarmStep $step): SwarmStep
    {
        if ($this->capturesInputs() && $this->capturesOutputs()) {
            return $step;
        }

        return new SwarmStep(
            agentClass: $step->agentClass,
            input: $this->capturedInput($step->input),
            output: $this->capturedOutput($step->output),
            artifacts: $this->capturesOutputs() ? $step->artifacts : [],
            metadata: $step->metadata,
        );
    }

    protected function capturedInput(string $input): string
    {
        return $this->capturesInputs() ? $input : self::REDACTED;
    }

    protected function capturedOutput(string $output): string
    {
        return $this->capturesOutputs() ? $output : self::REDACTED;
    }

    protected function capturesInputs(): bool
    {
        return (bool) $this->config->get('swarm.capture.inputs', true);
    }

    protected function capturesOutputs(): bool
    {
        return (bool) $this->config->get('swarm.capture.outputs', true);
    }

    protected function verifyOwnership(SwarmExecutionState $state): void
    {
        if (is_callable($state->verifyOwnership)) {
            ($state->verifyOwnership)();
        }
    }
}
