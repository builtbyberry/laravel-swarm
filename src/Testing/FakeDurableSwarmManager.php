<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing;

use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;

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

    public function pause(string $runId): bool
    {
        $this->fake->recordDurableOperation('pause');

        return true;
    }

    public function resume(string $runId): bool
    {
        $this->fake->recordDurableOperation('resume');

        return true;
    }

    public function cancel(string $runId): bool
    {
        $this->fake->recordDurableOperation('cancel');

        return true;
    }
}
