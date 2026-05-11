<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmStepGuardrail;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;

/**
 * Immutable context for {@see SwarmStepGuardrail}.
 *
 * @param  array<string, mixed>  $metadata  Step-scoped metadata (e.g. hierarchical node hints).
 */
final readonly class GuardrailStepContext
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $runId,
        public string $swarmClass,
        public Topology $topology,
        public ExecutionMode $executionMode,
        public int $stepIndex,
        public string $agentClass,
        public string $input,
        public string $output,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function fromState(
        SwarmExecutionState $state,
        int $stepIndex,
        string $agentClass,
        string $input,
        string $output,
        array $metadata = [],
    ): self {
        return new self(
            runId: $state->context->runId,
            swarmClass: $state->swarm::class,
            topology: $state->topology,
            executionMode: $state->executionMode,
            stepIndex: $stepIndex,
            agentClass: $agentClass,
            input: $input,
            output: $output,
            metadata: $metadata,
        );
    }
}
