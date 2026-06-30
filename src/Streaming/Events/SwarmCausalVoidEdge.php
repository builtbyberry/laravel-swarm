<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

/**
 * A typed void-edge appended to the causal log (#282).
 *
 * It is itself an event in the log — DB-sequenced like any other — that records
 * that `targetEventId` (the `event_uuid` of an earlier event) is voided with a
 * `voidType` and a `reason`. The voided event is never deleted; the fold layer
 * (#283) interprets the edge at read time. Runners and operators append it via
 * `CausalLogStore::appendVoidEdge()`; it carries its own `id` so it too is
 * addressable in the log.
 */
final class SwarmCausalVoidEdge extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public CausalVoidEdgeType $voidType,
        public string $targetEventId,
        public string $reason,
        public int $timestamp,
        // The rollup node whose digest reads in the target's place, for a
        // RolledUp edge (#289). Null for every other void type. It rides in the
        // payload JSON only — no dedicated column — since the fold reads it from
        // there and the lock/seal path keys on the existing void columns.
        public ?string $digestNodeId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'node_id' => $this->nodeId,
            'type' => 'swarm_causal_void_edge',
            'run_id' => $this->runId,
            'void_type' => $this->voidType->value,
            'target_event_id' => $this->targetEventId,
            'reason' => $this->reason,
            'digest_node_id' => $this->digestNodeId,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: self::stringValue($payload, 'id', self::newId()),
            runId: self::stringValue($payload, 'run_id'),
            voidType: CausalVoidEdgeType::from(self::stringValue($payload, 'void_type', CausalVoidEdgeType::Supersedes->value)),
            targetEventId: self::stringValue($payload, 'target_event_id'),
            reason: self::stringValue($payload, 'reason'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
            digestNodeId: self::nullableStringValue($payload, 'digest_node_id'),
        );
    }
}
