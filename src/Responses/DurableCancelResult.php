<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

/**
 * Outcome of a durable cancel request.
 *
 * A cancel is applied immediately only when the run is idle at a checkpoint.
 * A run that is mid-step is marked to cancel at its next boundary — that is a
 * definite SCHEDULED transition, not a no-op. The {@see $status} field reports
 * which of the two happened so callers never have to guess.
 */
class DurableCancelResult
{
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly string $topology,
        /** Either 'cancelled' (applied now) or 'cancel_scheduled' (will cancel at the next boundary). */
        public readonly string $status,
    ) {}

    /**
     * True when the run transitioned to cancelled immediately; false when the
     * cancel is scheduled for the next safe boundary.
     */
    public function isImmediate(): bool
    {
        return $this->status === 'cancelled';
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
            'status' => $this->status,
            'immediate' => $this->isImmediate(),
        ];
    }
}
