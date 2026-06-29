<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming;

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;

/**
 * A minimal {@see SwarmStreamEvent} whose `toArray()` returns an arbitrary
 * payload, for folding structural node/void shapes the #283 view reads by string
 * key. It lets the unit suite construct the pinned wire shapes (node.opened,
 * node.children-decided, node.closed, void-edge) without importing the #284
 * node-event classes, which do not exist on this branch.
 */
final class SyntheticCausalEvent extends SwarmStreamEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private array $payload) {}

    /**
     * A `swarm_node_opened` payload. `parent_node_id` null => root.
     */
    public static function nodeOpened(string $id, string $nodeId, ?string $parentNodeId, string $role = 'worker'): self
    {
        return new self([
            'id' => $id,
            'type' => 'swarm_node_opened',
            'run_id' => 'run-synthetic',
            'node_id' => $nodeId,
            'parent_node_id' => $parentNodeId,
            'role' => $role,
            'rationale' => null,
            'timestamp' => 0,
        ]);
    }

    /**
     * A `swarm_node_children_decided` payload. `$childNodeIds` order IS the
     * declared sibling/presentation order.
     *
     * @param  list<string>  $childNodeIds
     */
    public static function childrenDecided(string $id, string $parentNodeId, array $childNodeIds): self
    {
        return new self([
            'id' => $id,
            'type' => 'swarm_node_children_decided',
            'run_id' => 'run-synthetic',
            'node_id' => $parentNodeId,
            'child_node_ids' => $childNodeIds,
            'rationale' => null,
            'timestamp' => 0,
        ]);
    }

    /**
     * A `swarm_node_closed` payload.
     */
    public static function nodeClosed(string $id, string $nodeId, ?string $result = null): self
    {
        return new self([
            'id' => $id,
            'type' => 'swarm_node_closed',
            'run_id' => 'run-synthetic',
            'node_id' => $nodeId,
            'result' => $result,
            'timestamp' => 0,
        ]);
    }

    /**
     * A `swarm_causal_void_edge` payload targeting an earlier event.
     */
    public static function voidEdge(string $id, string $voidType, string $targetEventId, string $reason): self
    {
        return new self([
            'id' => $id,
            'type' => 'swarm_causal_void_edge',
            'run_id' => 'run-synthetic',
            'void_type' => $voidType,
            'target_event_id' => $targetEventId,
            'reason' => $reason,
            'timestamp' => 0,
        ]);
    }

    /**
     * A `swarm_causal_void_edge` of type `rolled_up` (#289), carrying the digest
     * node the target reads in its place.
     */
    public static function rolledUpEdge(string $id, string $targetEventId, string $digestNodeId, string $reason = 'rolled up'): self
    {
        return new self([
            'id' => $id,
            'type' => 'swarm_causal_void_edge',
            'run_id' => 'run-synthetic',
            'void_type' => 'rolled_up',
            'target_event_id' => $targetEventId,
            'reason' => $reason,
            'digest_node_id' => $digestNodeId,
            'timestamp' => 0,
        ]);
    }

    /**
     * A plain leaf event carrying an optional `node_id` (absent => null/top-level).
     */
    public static function leaf(string $id, ?string $nodeId = null): self
    {
        $payload = [
            'id' => $id,
            'type' => 'swarm_text_delta',
            'run_id' => 'run-synthetic',
            'timestamp' => 0,
        ];

        if ($nodeId !== null) {
            $payload['node_id'] = $nodeId;
        }

        return new self($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
