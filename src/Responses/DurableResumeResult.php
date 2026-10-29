<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Enums\DurableLifecycleStatus;

/**
 * Outcome of a durable resume request.
 *
 * Resuming a paused run either re-dispatches the next step, or — when the run
 * was paused while waiting on branch work, a signal, or a child swarm — re-arms
 * that waiting boundary instead of dispatching a step. The {@see $status} field
 * reports which state the run resumed into, and {@see $waitingBoundaryDispatched}
 * reports whether a waiting boundary was re-armed, so callers never have to guess.
 */
class DurableResumeResult
{
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly string $topology,
        /** Either DurableLifecycleStatus::Resumed (a step was re-dispatched) or ::Waiting (resumed back into a waiting boundary). */
        public readonly DurableLifecycleStatus $status,
        /**
         * True when resuming re-armed a waiting boundary rather than dispatching a step:
         * branch work re-dispatched, or a signal or child-swarm wait the run is waiting
         * on again. True whenever the status is Waiting; it does not mean a job was pushed.
         */
        public readonly bool $waitingBoundaryDispatched = false,
    ) {}

    /**
     * True when the run resumed back into a waiting boundary rather than
     * dispatching its next step.
     */
    public function isWaiting(): bool
    {
        return $this->status === DurableLifecycleStatus::Waiting;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'swarm_class' => $this->swarmClass,
            'topology' => $this->topology,
            'status' => $this->status->value,
            'waiting_boundary_dispatched' => $this->waitingBoundaryDispatched,
        ];
    }
}
