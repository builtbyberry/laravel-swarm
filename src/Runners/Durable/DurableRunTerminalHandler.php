<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

class DurableRunTerminalHandler
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected Dispatcher $events,
        protected DurableRunRecorder $recorder,
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
        protected DurableRunContext $runs,
        protected DurablePayloadCapture $payloads,
        protected DurableBranchCoordinator $branches,
        protected DurableChildSwarmCoordinator $children,
    ) {}

    /**
     * @param  array<string, mixed>  $run
     */
    public function failTimedOutRun(array $run, string $token, RunContext $context, int $stepLeaseSeconds, callable $dispatchStep): void
    {
        $runId = (string) $run['run_id'];
        $exception = new SwarmException("Durable swarm run [{$runId}] exceeded its configured timeout.");

        $this->recorder->fail($runId, $token, $exception, $context, $stepLeaseSeconds);
        $this->children->markChildTerminalIfNeeded($runId, 'failed', null, [
            'message' => $this->capture->failureMessage($exception),
            'class' => $exception::class,
            'timed_out' => true,
        ], $dispatchStep);
        $this->dispatchFailed($run, $context, $exception, ExecutionMode::Durable->value);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function cancelRun(array $run, string $token, RunContext $context, callable $dispatchStep, ?SwarmStep $step = null): void
    {
        $runId = (string) $run['run_id'];

        $this->recorder->cancel($runId, $token, $context, $step);
        $this->children->markChildTerminalIfNeeded($runId, 'cancelled', null, null, $dispatchStep);
        $this->events->dispatch(new SwarmCancelled(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            metadata: $this->payloads->eventMetadata($context),
            executionMode: ExecutionMode::Durable->value,
        ));
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function pauseRun(array $run, string $token, RunContext $context): void
    {
        $runId = (string) $run['run_id'];

        $this->recorder->pauseAtBoundary($runId, $token, $context);
        $this->events->dispatch(new SwarmPaused(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            metadata: $this->payloads->eventMetadata($context),
            executionMode: ExecutionMode::Durable->value,
        ));
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function failRun(array $run, string $token, Throwable $exception, RunContext $context, int $stepLeaseSeconds, callable $dispatchStep): void
    {
        $runId = (string) $run['run_id'];

        $this->recorder->fail($runId, $token, $exception, $context, $stepLeaseSeconds);
        $this->children->markChildTerminalIfNeeded($runId, 'failed', null, [
            'message' => $this->capture->failureMessage($exception),
            'class' => $exception::class,
        ], $dispatchStep);
        $this->dispatchFailed($run, $context, $exception, ExecutionMode::Durable->value);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function completeRun(array $run, string $token, RunContext $context, int $stepLeaseSeconds, ?SwarmStep $step, callable $dispatchStep): void
    {
        $runId = (string) $run['run_id'];
        $response = new SwarmResponse(
            output: (string) ($context->data['last_output'] ?? $context->input),
            steps: $step !== null ? [$step] : [],
            usage: is_array($context->metadata['usage'] ?? null) ? $context->metadata['usage'] : [],
            context: $context,
            artifacts: $context->artifacts,
            metadata: array_merge($context->metadata, [
                'run_id' => $runId,
                'topology' => $run['topology'],
            ]),
        );

        $capturedResponse = $this->limits->response($this->capture->response($response));
        $this->recorder->complete($runId, $token, $context, $capturedResponse, $stepLeaseSeconds, $step);
        $this->children->markChildTerminalIfNeeded($runId, 'completed', $capturedResponse->output, null, $dispatchStep);

        $this->events->dispatch(new SwarmCompleted(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            output: $capturedResponse->output,
            durationMs: $this->runs->durationMillisecondsFor($runId),
            metadata: $capturedResponse->metadata,
            artifacts: $capturedResponse->artifacts,
            executionMode: ExecutionMode::Durable->value,
        ));
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function failCurrentRunFromBranchFailures(array $run, string $token, RunContext $context, int $stepLeaseSeconds, ?string $parentNodeId, callable $dispatchStep): void
    {
        $runId = (string) $run['run_id'];
        $branches = $this->durableRuns->branchesFor($runId, $parentNodeId);
        $failed = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? null) === 'failed'));
        $message = 'Durable parallel branches failed: '.implode(', ', array_map(static fn (array $branch): string => (string) $branch['branch_id'], $failed));
        $exception = new SwarmException($message);
        $context->mergeMetadata([
            'durable_parallel_branches' => $this->branches->branchSummaries($branches),
        ]);

        $this->recorder->fail($runId, $token, $exception, $context, $stepLeaseSeconds);
        $this->children->markChildTerminalIfNeeded($runId, 'failed', null, [
            'message' => $this->capture->failureMessage($exception),
            'class' => $exception::class,
        ], $dispatchStep);
        $this->dispatchFailed($run, $context, $exception, $this->runs->publicLifecycleExecutionMode($run));
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function dispatchFailed(array $run, RunContext $context, Throwable $exception, string $executionMode): void
    {
        $runId = (string) $run['run_id'];

        $this->events->dispatch(new SwarmFailed(
            runId: $runId,
            swarmClass: $run['swarm_class'],
            topology: $run['topology'],
            exception: $this->capture->failureException($exception),
            durationMs: $this->runs->durationMillisecondsFor($runId),
            metadata: $this->payloads->eventMetadata($context),
            executionMode: $executionMode,
            exceptionClass: $exception::class,
        ));
    }
}
