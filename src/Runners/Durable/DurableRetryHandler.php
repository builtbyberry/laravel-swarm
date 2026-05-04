<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Contracts\ConfiguresDurableRetries;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableRetryPolicy;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Throwable;

class DurableRetryHandler
{
    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected Connection $connection,
        protected SwarmCapture $capture,
    ) {}

    /**
     * @param  array<string, mixed>  $run
     * @return array{scheduled: bool, dispatchStep: array{runId: string, stepIndex: int, connection: ?string, queue: ?string}|null}
     */
    public function scheduleRunRetryIfAllowed(array $run, Swarm $swarm, RunContext $context, string $token, int $stepLeaseSeconds, int $stepIndex, Throwable $exception): array
    {
        $policy = $this->resolveRetryPolicy($swarm, $this->agentClassForStep($swarm, $run, $stepIndex));

        if ($policy === null || $this->isNonRetryable($policy, $exception)) {
            return ['scheduled' => false, 'dispatchStep' => null];
        }

        $attempt = ((int) ($run['retry_attempt'] ?? 0)) + 1;

        if ($attempt > $policy->maxAttempts) {
            return ['scheduled' => false, 'dispatchStep' => null];
        }

        $nextRetryAt = Carbon::now('UTC')->addSeconds($policy->delayForAttempt($attempt));

        try {
            $this->connection->transaction(function () use ($run, $token, $policy, $attempt, $nextRetryAt, $context, $stepLeaseSeconds): void {
                $this->durableRuns->scheduleRetry($run['run_id'], $token, $policy->toArray(), $attempt, $nextRetryAt);
                $this->historyStore->syncDurableState($run['run_id'], 'pending', $this->capture->context($context), array_merge($context->metadata, [
                    'durable_retry_attempt' => $attempt,
                    'durable_next_retry_at' => $nextRetryAt->toJSON(),
                ]), $this->ttlSeconds(), false, $token, $stepLeaseSeconds);
            });
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            return ['scheduled' => true, 'dispatchStep' => null];
        }

        $dispatchStep = null;
        if ($policy->delayForAttempt($attempt) === 0) {
            $dispatchStep = [
                'runId' => $run['run_id'],
                'stepIndex' => (int) $run['next_step_index'],
                'connection' => $run['queue_connection'],
                'queue' => $run['queue_name'],
            ];
        }

        return ['scheduled' => true, 'dispatchStep' => $dispatchStep];
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $branch
     * @return array{scheduled: bool, dispatchBranch: array{runId: string, branchId: string, connection: ?string, queue: ?string}|null}
     */
    public function scheduleBranchRetryIfAllowed(array $run, array $branch, Swarm $swarm, RunContext $context, string $token, Throwable $exception): array
    {
        $policy = $this->resolveRetryPolicy($swarm, (string) $branch['agent_class']);

        if ($policy === null || $this->isNonRetryable($policy, $exception)) {
            return ['scheduled' => false, 'dispatchBranch' => null];
        }

        $attempt = ((int) ($branch['retry_attempt'] ?? 0)) + 1;

        if ($attempt > $policy->maxAttempts) {
            return ['scheduled' => false, 'dispatchBranch' => null];
        }

        $nextRetryAt = Carbon::now('UTC')->addSeconds($policy->delayForAttempt($attempt));

        try {
            $this->durableRuns->scheduleBranchRetry($run['run_id'], (string) $branch['branch_id'], $token, $policy->toArray(), $attempt, $nextRetryAt);
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            return ['scheduled' => true, 'dispatchBranch' => null];
        }

        $dispatchBranch = null;
        if ($policy->delayForAttempt($attempt) === 0) {
            $dispatchBranch = [
                'runId' => $run['run_id'],
                'branchId' => (string) $branch['branch_id'],
                'connection' => $branch['queue_connection'] ?? $run['queue_connection'],
                'queue' => $branch['queue_name'] ?? $run['queue_name'],
            ];
        }

        return ['scheduled' => true, 'dispatchBranch' => $dispatchBranch];
    }

    public function resolveRetryPolicy(Swarm $swarm, ?string $agentClass = null): ?DurableRetryPolicy
    {
        if ($agentClass !== null && $swarm instanceof ConfiguresDurableRetries) {
            $policy = $swarm->durableAgentRetryPolicy($agentClass);

            if ($policy instanceof DurableRetryPolicy) {
                return $policy;
            }
        }

        if ($agentClass !== null && class_exists($agentClass)) {
            $attributes = (new ReflectionClass($agentClass))->getAttributes(DurableRetry::class);

            if ($attributes !== []) {
                $retry = $attributes[0]->newInstance();

                return new DurableRetryPolicy($retry->maxAttempts, $retry->backoffSeconds, $retry->nonRetryable);
            }
        }

        if ($swarm instanceof ConfiguresDurableRetries) {
            return $swarm->durableRetryPolicy();
        }

        $attributes = (new ReflectionClass($swarm))->getAttributes(DurableRetry::class);

        if ($attributes !== []) {
            $retry = $attributes[0]->newInstance();

            return new DurableRetryPolicy($retry->maxAttempts, $retry->backoffSeconds, $retry->nonRetryable);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function agentClassForStep(Swarm $swarm, array $run, int $stepIndex): ?string
    {
        if ($run['topology'] === Topology::Sequential->value) {
            $agents = $swarm->agents();

            return isset($agents[$stepIndex]) ? $agents[$stepIndex]::class : null;
        }

        return null;
    }

    protected function isNonRetryable(DurableRetryPolicy $policy, Throwable $exception): bool
    {
        foreach ($policy->nonRetryable as $class) {
            if (is_a($exception, $class)) {
                return true;
            }
        }

        return false;
    }

    protected function ttlSeconds(): int
    {
        return (int) $this->config->get('swarm.context.ttl', 3600);
    }
}
