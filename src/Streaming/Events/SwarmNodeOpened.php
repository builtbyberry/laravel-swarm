<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

/**
 * A run-structure node opening on the causal log (#284).
 *
 * "Structure as payload": the node grammar makes a run's shape first-class on
 * the append-only log. This event is appended before any substantive event
 * tagged with the node's id, so a reader (#283) folding the log always opens a
 * node before it sees content belonging to it — causal completeness.
 *
 * By convention the node is self-identifying: `nodeId` equals `id`, so the
 * opened event carries the same node id every later event tags itself with.
 * `parentNodeId` is the enclosing node's id, or null for a root node.
 *
 * INVARIANT for emitters (incl. the #285 dynamic/fan-out walker): every
 * `SwarmNodeOpened` must be tagged with its own id — `(new SwarmNodeOpened(id:
 * $node->id, ...))->withNodeId($node->id)`. The fold layer (#283) indexes a node
 * by its `node_id` tag, so a node.opened left untagged (null `node_id`) is
 * invisible to the fold and silently drops that node's subtree from presentation
 * order and abandonment. This is asserted on real runs in
 * CausalLogViewRealLogTest; it is enforced by discipline, not the constructor,
 * so the uniform `node_id` round-trip carry stays unbroken.
 */
final class SwarmNodeOpened extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public ?string $parentNodeId,
        public string $role,
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
            'type' => 'swarm_node_opened',
            'run_id' => $this->runId,
            'parent_node_id' => $this->parentNodeId,
            'role' => $this->role,
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
            parentNodeId: self::nullableStringValue($payload, 'parent_node_id'),
            role: self::stringValue($payload, 'role'),
            rationale: self::nullableStringValue($payload, 'rationale'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
        );
    }
}
