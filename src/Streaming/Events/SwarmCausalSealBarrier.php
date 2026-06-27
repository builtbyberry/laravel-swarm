<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

/**
 * Infrastructure marker event that closes the retractable window for a run.
 *
 * Emitted by the streaming runner immediately after SwarmStreamEnd, recorded
 * into the hot event log (swarm_stream_events). The barrier's DB auto-increment
 * `id` is the graduation boundary: the compactor (#287) graduates all events
 * with `id < barrier.id` to cold storage, then advances the base pointer to
 * `barrier.id` so TieredStreamEventStore stitches the tiers correctly.
 *
 * @internal
 */
final class SwarmCausalSealBarrier extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public int $timestamp,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'node_id' => $this->nodeId,
            'type' => 'swarm_causal_seal_barrier',
            'run_id' => $this->runId,
            'timestamp' => $this->timestamp,
        ];
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            id: self::stringValue($payload, 'id', self::newId()),
            runId: self::stringValue($payload, 'run_id'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
        );
    }
}
