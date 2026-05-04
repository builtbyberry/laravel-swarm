<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Attributes\DurableDetails;
use BuiltByBerry\LaravelSwarm\Attributes\DurableLabels;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmStart;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use ReflectionClass;

class DurableSwarmStarter
{
    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
        protected DurableRunContext $runs,
        protected DurableJobDispatcher $jobs,
    ) {}

    public function start(Swarm $swarm, RunContext $context, Topology $topology, int $timeoutSeconds, int $totalSteps, DurableParallelFailurePolicy $parallelFailurePolicy = DurableParallelFailurePolicy::CollectFailures): DurableSwarmStart
    {
        $this->limits->checkInput($context->input);

        return $this->connection->transaction(function () use ($swarm, $context, $topology, $timeoutSeconds, $totalSteps, $parallelFailurePolicy): DurableSwarmStart {
            $contextTtl = $this->runs->ttlSeconds();
            $connection = $this->config->get('swarm.durable.queue.connection');
            $queue = $this->config->get('swarm.durable.queue.name');

            $context->mergeMetadata([
                'swarm_class' => $swarm::class,
                'topology' => $topology->value,
                'execution_mode' => ExecutionMode::Durable->value,
                'completed_steps' => 0,
                'total_steps' => $totalSteps,
                'durable_parallel_failure_policy' => $parallelFailurePolicy->value,
            ]);

            $this->applyMetadataAttributes($swarm, $context);

            $this->historyStore->start($context->runId, $swarm::class, $topology->value, $this->capture->context($context), $context->metadata, $contextTtl);
            $this->contextStore->put($this->capture->activeContext($context), $contextTtl);
            $this->historyStore->syncDurableState($context->runId, 'pending', $this->capture->context($context), $context->metadata, $contextTtl, false);

            $this->durableRuns->create([
                'run_id' => $context->runId,
                'swarm_class' => $swarm::class,
                'topology' => $topology->value,
                'execution_mode' => ExecutionMode::Durable->value,
                'status' => 'pending',
                'next_step_index' => 0,
                'current_step_index' => null,
                'total_steps' => $totalSteps,
                'timeout_at' => now('UTC')->addSeconds($timeoutSeconds),
                'step_timeout_seconds' => $this->resolveStepTimeoutSeconds(),
                'execution_token' => null,
                'leased_until' => null,
                'pause_requested_at' => null,
                'cancel_requested_at' => null,
                'queue_connection' => $connection,
                'queue_name' => $queue,
                'finished_at' => null,
                'parent_run_id' => is_string($context->metadata['parent_run_id'] ?? null) ? $context->metadata['parent_run_id'] : null,
            ]);

            if ($context->labels() !== []) {
                $this->durableRuns->updateLabels($context->runId, $context->labels());
            }

            if ($context->details() !== []) {
                $this->durableRuns->updateDetails($context->runId, $context->details());
            }

            return new DurableSwarmStart($context->runId, $this->jobs->makeStepJob($context->runId, 0, $connection, $queue));
        });
    }

    protected function applyMetadataAttributes(Swarm $swarm, RunContext $context): void
    {
        $reflection = new ReflectionClass($swarm);
        $labels = $reflection->getAttributes(DurableLabels::class);
        $details = $reflection->getAttributes(DurableDetails::class);

        if ($labels !== []) {
            $context->withLabels($labels[0]->newInstance()->labels);
        }

        if ($details !== []) {
            $context->withDetails($details[0]->newInstance()->details);
        }
    }

    protected function resolveStepTimeoutSeconds(): int
    {
        $seconds = (int) $this->config->get('swarm.durable.step_timeout', 300);

        if ($seconds <= 0) {
            throw new SwarmException('Durable swarm step timeout must be a positive integer.');
        }

        return $seconds;
    }
}
