<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Throwable;

/**
 * Run-level audit emit facade.
 *
 * Centralizes the run.started / run.completed / run.failed payload shape so
 * the runner orchestrator never composes audit dictionaries inline. Delegates
 * sink dispatch, signing, and failure-policy routing to SwarmAuditDispatcher.
 *
 * Keeping this seam at the run level (rather than wrapping every emit anywhere)
 * lets the dispatcher stay generic while the runner stays free of the
 * categorization vocabulary.
 *
 * @internal
 */
class RunAuditEmitter
{
    public function __construct(
        protected SwarmAuditDispatcher $dispatcher,
    ) {}

    public function emitRunStarted(
        RunContext $context,
        string $swarmClass,
        Topology $topology,
        ExecutionMode $executionMode,
    ): void {
        $this->dispatcher->emit('run.started', [
            'run_id' => $context->runId,
            'parent_run_id' => $context->metadata['parent_run_id'] ?? null,
            'swarm_class' => $swarmClass,
            'topology' => $topology->value,
            'execution_mode' => $executionMode->value,
            'status' => 'started',
            ...$this->dispatcher->metadata($context->metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $responseMetadata
     */
    public function emitRunCompleted(
        RunContext $context,
        string $swarmClass,
        Topology $topology,
        ExecutionMode $executionMode,
        int $durationMs,
        array $responseMetadata,
    ): void {
        $this->dispatcher->emit('run.completed', [
            'run_id' => $context->runId,
            'parent_run_id' => $context->metadata['parent_run_id'] ?? null,
            'swarm_class' => $swarmClass,
            'topology' => $topology->value,
            'execution_mode' => $executionMode->value,
            'status' => 'completed',
            'duration_ms' => $durationMs,
            ...$this->dispatcher->metadata($responseMetadata),
        ]);
    }

    public function emitRunFailed(
        RunContext $context,
        string $swarmClass,
        Topology $topology,
        ExecutionMode $executionMode,
        Throwable $exception,
        int $durationMs,
    ): void {
        $this->dispatcher->emit('run.failed', [
            'run_id' => $context->runId,
            'parent_run_id' => $context->metadata['parent_run_id'] ?? null,
            'swarm_class' => $swarmClass,
            'topology' => $topology->value,
            'execution_mode' => $executionMode->value,
            'status' => 'failed',
            'exception_class' => $exception::class,
            'duration_ms' => $durationMs,
            ...$this->dispatcher->metadata($context->metadata),
        ]);
    }
}
