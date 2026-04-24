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
        $topologyType = match ($event->topology) {
            'parallel' => 'swarm_topology_parallel',
            'hierarchical' => 'swarm_topology_hierarchical',
            default => 'swarm_topology_sequential',
        };

        $this->pulse->lazy(function () use ($event, $status, $timestamp, $topologyType): void {
            $this->pulse->record(
                type: 'swarm_run',
                key: SwarmPulseKey::runStatus($event->swarmClass, $event->topology, $status),
                timestamp: $timestamp,
            )->count()->onlyBuckets();

            $this->pulse->record(
                type: 'swarm_run_total',
                key: $event->swarmClass,
                value: 1,
                timestamp: $timestamp,
            )->sum();

            if ($status === 'failed') {
                $this->pulse->record(
                    type: 'swarm_run_failed',
                    key: $event->swarmClass,
                    value: 1,
                    timestamp: $timestamp,
                )->sum();
            }

            $this->pulse->record(
                type: $topologyType,
                key: $event->swarmClass,
                value: 1,
                timestamp: $timestamp,
            )->sum();

            $this->pulse->record(
                type: 'swarm_run_duration',
                key: SwarmPulseKey::runDuration($event->swarmClass, $event->topology),
                value: $event->durationMs,
                timestamp: $timestamp,
            )->avg()->count()->onlyBuckets();

            $this->pulse->record(
                type: 'swarm_run_duration_total',
                key: $event->swarmClass,
                value: $event->durationMs,
                timestamp: $timestamp,
            )->avg()->count();

            $this->pulse->record(
                type: 'swarm_run_duration_total_ms',
                key: $event->swarmClass,
                value: $event->durationMs,
                timestamp: $timestamp,
            )->sum();

            $this->pulse->record(
                type: 'swarm_run_duration_samples',
                key: $event->swarmClass,
                value: 1,
                timestamp: $timestamp,
            )->sum();
        });
    }
}
