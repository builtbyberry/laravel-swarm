<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Throwable;

/**
 * @internal Durable execution persistence coordinator. Not part of the public package API.
 */
class DurableRunRecorder
{
    public function __construct(
        protected ConfigRepository $config,
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected Connection $connection,
        protected SwarmCapture $capture,
    ) {}

    public function fail(string $runId, string $token, Throwable $exception, RunContext $context, int $stepLeaseSeconds): void
    {
        $this->connection->transaction(function () use ($runId, $token, $exception, $context, $stepLeaseSeconds): void {
            $this->durableRuns->markFailed($runId, $token);
            $this->contextStore->put($this->capture->terminalContext($context), $this->ttlSeconds());
            $this->historyStore->fail($runId, $exception, $this->ttlSeconds(), $token, $stepLeaseSeconds);
        });
    }

    public function cancel(string $runId, string $token, RunContext $context, ?SwarmStep $step = null): void
    {
        $this->connection->transaction(function () use ($runId, $token, $context, $step): void {
            $this->persistStepArtifacts($runId, $step);
            $this->durableRuns->markCancelled($runId, $token);
            $this->contextStore->put($this->capture->terminalContext($context), $this->ttlSeconds());
            $this->historyStore->syncDurableState($runId, 'cancelled', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), true);
        });
    }

    public function complete(string $runId, string $token, RunContext $context, SwarmResponse $capturedResponse, int $stepLeaseSeconds, ?SwarmStep $step = null): void
    {
        $this->connection->transaction(function () use ($runId, $token, $context, $capturedResponse, $stepLeaseSeconds, $step): void {
            $this->persistStepArtifacts($runId, $step);
            $this->durableRuns->markCompleted($runId, $token);
            $this->contextStore->put($this->capture->terminalContext($context), $this->ttlSeconds());
            $this->historyStore->complete($runId, $capturedResponse, $this->ttlSeconds(), $token, $stepLeaseSeconds);
        });
    }

    public function checkpointHierarchical(
        string $runId,
        string $token,
        int $nextStepIndex,
        RunContext $context,
        int $stepLeaseSeconds,
        DurableHierarchicalStepResult $result,
        ?SwarmStep $step = null,
    ): void {
        $this->connection->transaction(function () use ($runId, $token, $nextStepIndex, $context, $stepLeaseSeconds, $result, $step): void {
            $this->historyStore->syncDurableState($runId, 'pending', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false, $token, $stepLeaseSeconds);
            $this->persistStepArtifacts($runId, $step);
            $this->durableRuns->checkpointHierarchicalStep(
                runId: $runId,
                executionToken: $token,
                nextStepIndex: $nextStepIndex,
                context: $this->capture->activeContext($context),
                ttlSeconds: $this->ttlSeconds(),
                routeCursor: $result->routeCursor,
                routePlan: $result->routePlan,
                nodeOutput: $result->nodeOutput,
                totalSteps: $result->totalSteps,
            );
        });
    }

    public function checkpointSequential(string $runId, string $token, int $nextStepIndex, RunContext $context, int $stepLeaseSeconds): void
    {
        $this->connection->transaction(function () use ($runId, $token, $nextStepIndex, $context, $stepLeaseSeconds): void {
            $this->historyStore->syncDurableState($runId, 'pending', $this->capture->context($context), $context->metadata, $this->ttlSeconds(), false, $token, $stepLeaseSeconds);
            $this->durableRuns->releaseForNextStep($runId, $token, $nextStepIndex);
        });
    }

    protected function persistStepArtifacts(string $runId, ?SwarmStep $step): void
    {
        if ($step === null || ! $this->capture->capturesArtifacts()) {
            return;
        }

        $this->artifactRepository->storeMany($runId, $step->artifacts, $this->ttlSeconds());
    }

    protected function ttlSeconds(): int
    {
        return (int) $this->config->get('swarm.context.ttl', 3600);
    }
}
