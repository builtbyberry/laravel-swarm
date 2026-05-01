<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Foundation\Bus\PendingDispatch;
use Laravel\Ai\FakePendingDispatch;

class DurableSwarmResponse
{
    public function __construct(
        protected PendingDispatch $dispatchable,
        protected DurableSwarmManager $manager,
        public readonly string $runId,
    ) {}

    public function signal(string $name, mixed $payload = null, ?string $idempotencyKey = null): DurableSignalResult
    {
        return $this->manager->signal($this->runId, $name, $payload, $idempotencyKey);
    }

    public function inspect(): DurableRunDetail
    {
        return $this->manager->inspect($this->runId);
    }

    public function pause(): bool
    {
        return $this->manager->pause($this->runId);
    }

    public function resume(): bool
    {
        return $this->manager->resume($this->runId);
    }

    public function cancel(): bool
    {
        return $this->manager->cancel($this->runId);
    }

    /**
     * Proxy missing method calls to the pending dispatch instance.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (! method_exists($this->dispatchable, $method)) {
            throw new \BadMethodCallException("Method [{$method}] does not exist on the durable swarm response.");
        }

        $result = $this->dispatchable->{$method}(...$arguments);

        if ($result instanceof PendingDispatch) {
            $this->dispatchable = $result;
            $this->syncQueueRouting();

            return $this;
        }

        return $result;
    }

    protected function syncQueueRouting(): void
    {
        if ($this->dispatchable instanceof FakePendingDispatch) {
            return;
        }

        $job = $this->dispatchable->getJob();

        $this->manager->updateQueueRouting(
            $this->runId,
            property_exists($job, 'connection') ? $job->connection : null,
            property_exists($job, 'queue') ? $job->queue : null,
        );
    }
}
