<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

class DurableStepExecutionBuilder
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected Dispatcher $events,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected Application $application,
        protected DurableRunContext $runs,
        protected DurablePayloadCapture $payloads,
    ) {}

    /**
     * @param  array<string, mixed>  $run
     * @return array{0: Swarm, 1: SwarmExecutionState}
     */
    public function build(array $run, string $token, RunContext $context, int $expectedStepIndex, int $stepLeaseSeconds): array
    {
        $runId = (string) $run['run_id'];
        $swarm = $this->application->make($run['swarm_class']);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve durable swarm [{$run['swarm_class']}] from the container.");
        }

        $this->connection->transaction(function () use ($runId, $token, $expectedStepIndex, $context, $stepLeaseSeconds): void {
            $this->durableRuns->markRunning($runId, $token, $expectedStepIndex);
            $this->historyStore->syncDurableState($runId, 'running', $this->capture->context($context), $context->metadata, $this->runs->ttlSeconds(), false, $token, $stepLeaseSeconds);
        });

        if ($expectedStepIndex === 0 && $run['current_step_index'] === null) {
            $this->events->dispatch(new SwarmStarted(
                runId: $runId,
                swarmClass: $run['swarm_class'],
                topology: $run['topology'],
                input: $this->capture->input($context->input),
                metadata: $this->payloads->eventMetadata($context),
                executionMode: ExecutionMode::Durable->value,
            ));
        }

        $timeoutSeconds = max((int) ceil((Carbon::parse($run['timeout_at'], 'UTC')->diffInSeconds(now('UTC'), false)) * -1), 1);

        return [$swarm, new SwarmExecutionState(
            swarm: $swarm,
            topology: Topology::from($run['topology']),
            executionMode: ExecutionMode::Durable,
            deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: (int) $run['total_steps'],
            ttlSeconds: $this->runs->ttlSeconds(),
            leaseSeconds: $stepLeaseSeconds,
            executionToken: $token,
            verifyOwnership: fn (): null => $this->durableRuns->assertOwned($runId, $token),
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
            queueHierarchicalParallelCoordination: null,
        )];
    }
}
