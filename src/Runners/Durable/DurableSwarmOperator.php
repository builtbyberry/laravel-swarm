<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmOperator;
use BuiltByBerry\LaravelSwarm\Responses\DurableCancelResult;
use BuiltByBerry\LaravelSwarm\Responses\DurablePauseResult;
use BuiltByBerry\LaravelSwarm\Responses\DurableResumeResult;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;

/**
 * Thin, stateless adapter implementing the public {@see SwarmOperator}
 * control contract.
 *
 * It holds no per-run state and delegates each verb to the durable manager,
 * which wires the already-decomposed lifecycle / signal / recovery
 * collaborators together (dispatch routing, child cascade, waiting-boundary
 * re-arm). Keeping the wiring in one @internal place — and this adapter free of
 * memoized state — means the container can bind it as a shared singleton that
 * is safe under Octane and concurrent in-process runs.
 *
 * The @internal {@see DurableSwarmManager} intentionally does NOT implement the
 * public contract: it is a broad facade over engine mechanics, so only this
 * narrow control surface is promoted.
 */
class DurableSwarmOperator implements SwarmOperator
{
    public function __construct(
        protected DurableSwarmManager $manager,
    ) {}

    public function pause(string $runId): DurablePauseResult
    {
        return $this->manager->pause($runId);
    }

    public function resume(string $runId): DurableResumeResult
    {
        return $this->manager->resume($runId);
    }

    public function cancel(string $runId): DurableCancelResult
    {
        return $this->manager->cancel($runId);
    }

    public function signal(string $runId, string $name, mixed $payload = null, ?string $idempotencyKey = null): DurableSignalResult
    {
        return $this->manager->signal($runId, $name, $payload, $idempotencyKey);
    }

    /**
     * @return array<int, string>
     */
    public function recover(?string $runId = null, ?string $swarmClass = null, int $limit = 50): array
    {
        return $this->manager->recover($runId, $swarmClass, $limit);
    }
}
