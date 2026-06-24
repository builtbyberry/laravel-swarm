<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

/**
 * A deciding node declaring its children on the causal log (#284).
 *
 * Appended by a decider once it has finished deliberating, this event closes
 * the decision: `childNodeIds` is the chosen children in declared order — the
 * list order IS the sibling/presentation order a reader (#283) walks. `nodeId`
 * is the deciding (parent) node's id; the children themselves open via their
 * own {@see SwarmNodeOpened} events later in the log.
 */
final class SwarmNodeChildrenDecided extends SwarmStreamEvent
{
    /**
     * @param  array<int, string>  $childNodeIds
     */
    public function __construct(
        public string $id,
        public string $runId,
        public array $childNodeIds,
        public ?string $rationale,
        public int $timestamp,
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
            'type' => 'swarm_node_children_decided',
            'run_id' => $this->runId,
            'child_node_ids' => $this->childNodeIds,
            'rationale' => $this->rationale,
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
            childNodeIds: self::stringList($payload, 'child_node_ids'),
            rationale: self::nullableStringValue($payload, 'rationale'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
        );
    }
}
