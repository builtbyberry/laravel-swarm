<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing;

use BuiltByBerry\LaravelSwarm\Responses\DurableCancelResult;
use BuiltByBerry\LaravelSwarm\Responses\DurablePauseResult;
use BuiltByBerry\LaravelSwarm\Responses\DurableResumeResult;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;

/**
 * @internal
 */
class FakeDurableSwarmManager extends DurableSwarmManager
{
    public function __construct(
        protected SwarmFake $fake,
    ) {}

    public function signal(string $runId, string $name, mixed $payload = null, ?string $idempotencyKey = null): DurableSignalResult
    {
        $this->fake->recordDurableSignal($name, $payload, $idempotencyKey);

        return new DurableSignalResult(
            runId: $runId,
            name: $name,
            status: 'accepted',
            accepted: true,
            duplicate: false,
            signal: [
                'run_id' => $runId,
                'name' => $name,
                'payload' => $payload,
                'idempotency_key' => $idempotencyKey,
            ],
        );
    }

    public function inspect(string $runId): DurableRunDetail
    {
        $this->fake->recordDurableInspect();

        return $this->fake->durableRunDetail($runId);
    }

    public function pause(string $runId): DurablePauseResult
    {
        $this->fake->recordDurableOperation('pause');

        return new DurablePauseResult(
            runId: $runId,
            swarmClass: $this->fake->fakeSwarmClass(),
            topology: $this->fake->fakeTopology(),
            status: 'paused',
        );
    }

    public function resume(string $runId): DurableResumeResult
    {
        $this->fake->recordDurableOperation('resume');

        return new DurableResumeResult(
            runId: $runId,
            swarmClass: $this->fake->fakeSwarmClass(),
            topology: $this->fake->fakeTopology(),
            status: 'resumed',
        );
    }

    public function cancel(string $runId): DurableCancelResult
    {
        $this->fake->recordDurableOperation('cancel');

        return new DurableCancelResult(
            runId: $runId,
            swarmClass: $this->fake->fakeSwarmClass(),
            topology: $this->fake->fakeTopology(),
            status: 'cancelled',
        );
    }
}
