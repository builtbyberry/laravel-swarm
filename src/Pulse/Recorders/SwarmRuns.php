<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Pulse\Recorders;

use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Pulse\Support\SwarmPulseKey;
use Carbon\CarbonImmutable;
use Laravel\Pulse\Pulse;

class SwarmRuns
{
    /**
     * @var list<class-string>
     */
    public array $listen = [
        SwarmCompleted::class,
        SwarmFailed::class,
    ];

    public function __construct(
        protected Pulse $pulse,
    ) {}

    public function record(SwarmCompleted|SwarmFailed $event): void
    {
        $timestamp = CarbonImmutable::now()->getTimestamp();
        $status = $event instanceof SwarmCompleted ? 'completed' : 'failed';

        $this->pulse->lazy(function () use ($event, $status, $timestamp): void {
            $this->pulse->record(
                type: 'swarm_run',
                key: SwarmPulseKey::runStatus($event->swarmClass, $event->topology, $status),
                timestamp: $timestamp,
            )->count()->onlyBuckets();

            $this->pulse->record(
                type: 'swarm_run_duration',
                key: SwarmPulseKey::runDuration($event->swarmClass, $event->topology),
                value: $event->durationMs,
                timestamp: $timestamp,
            )->avg()->count()->onlyBuckets();
        });
    }
}
