<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Pulse\Recorders;

use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Pulse\Support\SwarmPulseKey;
use Carbon\CarbonImmutable;
use Laravel\Pulse\Pulse;

class SwarmStepDurations
{
    /**
     * @var list<class-string>
     */
    public array $listen = [
        SwarmStepCompleted::class,
    ];

    public function __construct(
        protected Pulse $pulse,
    ) {}

    public function record(SwarmStepCompleted $event): void
    {
        $timestamp = CarbonImmutable::now()->getTimestamp();

        $this->pulse->lazy(function () use ($event, $timestamp): void {
            $this->pulse->record(
                type: 'swarm_step_duration',
                key: SwarmPulseKey::stepDuration($event->swarmClass, $event->topology, $event->agentClass),
                value: $event->durationMs,
                timestamp: $timestamp,
            )->avg()->count()->onlyBuckets();
        });
    }
}
