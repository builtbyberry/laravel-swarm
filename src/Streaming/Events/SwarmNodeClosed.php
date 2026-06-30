<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

/**
 * A run-structure node closing on the causal log (#284).
 *
 * The terminal bracket for a node opened by {@see SwarmNodeOpened}: every
 * event tagged with this `nodeId` precedes it. `result` is the node's final
 * output when one is captured, or null when the close carries no payload.
 */
final class SwarmNodeClosed extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public ?string $result,
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
            'type' => 'swarm_node_closed',
            'run_id' => $this->runId,
            'result' => $this->result,
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
            result: self::nullableStringValue($payload, 'result'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
        );
    }
}
