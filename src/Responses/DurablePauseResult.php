<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Enums\DurableLifecycleStatus;

/**
 * Outcome of a durable pause request.
 *
 * A pause is applied immediately only when the run is idle at a checkpoint.
 * A run that is mid-step is marked to pause at its next boundary — that is a
 * definite SCHEDULED transition, not a no-op. The {@see $status} field reports
 * which of the two happened so callers never have to guess.
 */
readonly class DurablePauseResult
{
    public function __construct(
        public string $runId,
        public string $swarmClass,
        public string $topology,
        /** Either DurableLifecycleStatus::Paused (applied now) or ::PauseScheduled (will pause at the next boundary). */
        public DurableLifecycleStatus $status,
    ) {}

    /**
     * True when the run transitioned to paused immediately; false when the
     * pause is scheduled for the next safe boundary.
     */
    public function isImmediate(): bool
    {
        return $this->status === DurableLifecycleStatus::Paused;
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
            'immediate' => $this->isImmediate(),
        ];
    }
}
