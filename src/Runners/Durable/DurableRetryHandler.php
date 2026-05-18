<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Contracts\ConfiguresDurableRetries;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableRetryPolicy;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Throwable;

/**
 * @internal
 */
class DurableRetryHandler
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected Connection $connection,
        protected SwarmCapture $capture,
        protected DurableRunContext $runs,
        protected DurableOutbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $run
     * @return array{scheduled: bool}
     */
    public function scheduleRunRetryIfAllowed(array $run, Swarm $swarm, RunContext $context, string $token, int $stepLeaseSeconds, int $stepIndex, Throwable $exception): array
    {
        $policy = $this->resolveRetryPolicy($swarm, $this->agentClassForStep($swarm, $run, $stepIndex));

        if ($policy === null || $this->isNonRetryable($policy, $exception)) {
            return ['scheduled' => false];
        }

        $attempt = ((int) ($run['retry_attempt'] ?? 0)) + 1;

        if ($attempt > $policy->maxAttempts) {
            return ['scheduled' => false];
        }

        $nextRetryAt = Carbon::now('UTC')->addSeconds($policy->delayForAttempt($attempt));
        $isZeroDelay = $policy->delayForAttempt($attempt) === 0;

        try {
            $this->connection->transaction(function () use ($run, $token, $policy, $attempt, $nextRetryAt, $context, $stepLeaseSeconds, $isZeroDelay): void {
                $this->durableRuns->scheduleRetry($run['run_id'], $token, $policy->toArray(), $attempt, $nextRetryAt);
                $this->historyStore->syncDurableState($run['run_id'], 'pending', $this->capture->context($context), array_merge($context->metadata, [
                    'durable_retry_attempt' => $attempt,
                    'durable_next_retry_at' => $nextRetryAt->toJSON(),
                ]), $this->runs->ttlSeconds(), false, $token, $stepLeaseSeconds);
                // Zero-delay retries are enqueued inside the transaction so the
                // outbox row and the retry state change are always atomic.
                if ($isZeroDelay) {
                    $this->outbox->enqueueStep($run['run_id'], (int) $run['next_step_index'], $run['queue_connection'], $run['queue_name']);
                }
            });
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            // The execution lease expired before or during the retry transaction.
            // Another process (e.g. swarm:recover) must have taken ownership.
            // Returning 'scheduled: true' prevents the caller from failing the run —
            // recovery will redispatch it when it next polls.
            return ['scheduled' => true];
        }

        return ['scheduled' => true];
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $branch
     * @return array{scheduled: bool}
     */
    public function scheduleBranchRetryIfAllowed(array $run, array $branch, Swarm $swarm, RunContext $context, string $token, Throwable $exception): array
    {
        $policy = $this->resolveRetryPolicy($swarm, (string) $branch['agent_class']);

        if ($policy === null || $this->isNonRetryable($policy, $exception)) {
            return ['scheduled' => false];
        }

        $attempt = ((int) ($branch['retry_attempt'] ?? 0)) + 1;

        if ($attempt > $policy->maxAttempts) {
            return ['scheduled' => false];
        }

        $nextRetryAt = Carbon::now('UTC')->addSeconds($policy->delayForAttempt($attempt));
        $isZeroDelay = $policy->delayForAttempt($attempt) === 0;

        try {
            $this->connection->transaction(function () use ($run, $branch, $token, $policy, $attempt, $nextRetryAt, $isZeroDelay): void {
                $this->durableRuns->scheduleBranchRetry($run['run_id'], (string) $branch['branch_id'], $token, $policy->toArray(), $attempt, $nextRetryAt);
                if ($isZeroDelay) {
                    $this->outbox->enqueueBranch(
                        $run['run_id'],
                        (string) $branch['branch_id'],
                        $branch['queue_connection'] ?? $run['queue_connection'],
                        $branch['queue_name'] ?? $run['queue_name'],
                    );
                }
            });
        } catch (LostDurableLeaseException|LostSwarmLeaseException) {
            // Same semantics as the run retry catch above — lease lost, recovery takes over.
            return ['scheduled' => true];
        }

        return ['scheduled' => true];
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
}
