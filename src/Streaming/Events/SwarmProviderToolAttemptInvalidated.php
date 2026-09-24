<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;

/**
 * Per-node attempt watermark retaining its original provider-tool event name.
 * See {@see CausalLogView} for interpretation.
 */
final class SwarmProviderToolAttemptInvalidated extends SwarmStreamEvent
{
    public function __construct(public string $id, public string $runId, ?string $nodeId,
        public int $beforeEpoch, public int $timestamp)
    {
        $this->nodeId = $nodeId;
        $this->attemptEpoch = $beforeEpoch;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'invocation_id' => $this->invocationId, 'type' => 'swarm_provider_tool_attempt_invalidated',
            'run_id' => $this->runId, 'node_id' => $this->nodeId,
            'before_epoch' => $this->beforeEpoch, 'timestamp' => $this->timestamp];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(self::stringValue($payload, 'id'), self::stringValue($payload, 'run_id'),
            self::nullableStringValue($payload, 'node_id'), self::intValue($payload, 'before_epoch'),
            self::intValue($payload, 'timestamp'));
    }
}
