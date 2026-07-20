<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Enums\DurableLifecycleStatus;

/**
 * Outcome of a durable cancel request.
 *
 * A cancel is applied immediately only when the run is idle at a checkpoint.
 * A run that is mid-step is marked to cancel at its next boundary — that is a
 * definite SCHEDULED transition, not a no-op. The {@see $status} field reports
 * which of the two happened so callers never have to guess.
 */
readonly class DurableCancelResult
{
    public function __construct(
        public string $runId,
        public string $swarmClass,
        public string $topology,
        /** Either DurableLifecycleStatus::Cancelled (applied now) or ::CancelScheduled (will cancel at the next boundary). */
        public DurableLifecycleStatus $status,
    ) {}

    /**
     * True when the run transitioned to cancelled immediately; false when the
     * cancel is scheduled for the next safe boundary.
     */
    public function isImmediate(): bool
    {
        return $this->status === DurableLifecycleStatus::Cancelled;
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
