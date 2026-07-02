<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

/**
 * Outcome of a durable pause request.
 *
 * A pause is applied immediately only when the run is idle at a checkpoint.
 * A run that is mid-step is marked to pause at its next boundary — that is a
 * definite SCHEDULED transition, not a no-op. The {@see $status} field reports
 * which of the two happened so callers never have to guess.
 */
class DurablePauseResult
{
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        public readonly string $topology,
        /** Either 'paused' (applied now) or 'pause_scheduled' (will pause at the next boundary). */
        public readonly string $status,
    ) {}

    /**
     * True when the run transitioned to paused immediately; false when the
     * pause is scheduled for the next safe boundary.
     */
    public function isImmediate(): bool
    {
        return $this->status === 'paused';
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
