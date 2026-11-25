<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Streaming\PayloadAvailability;

/**
 * A fragment of a tool call's input as the model writes it. Concatenating the
 * deltas for one `toolId` gives the arguments text the matching
 * {@see SwarmToolCall} carries decoded. `delta` is null when output capture
 * withholds it (`payloadAvailability` says why): a fragment of arguments text can
 * end inside a key, so it cannot be redacted key by key the way the decoded
 * arguments of a tool call are.
 */
final class SwarmToolInputDelta extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public string $toolId,
        public ?string $delta,
        public int $timestamp,
        public PayloadAvailability $payloadAvailability = PayloadAvailability::Unknown,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            ...$this->transportIdentity(),
            'type' => 'swarm_tool_input_delta',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'tool_id' => $this->toolId,
            'delta' => $this->delta,
            ...($this->payloadAvailability === PayloadAvailability::Unknown
                ? []
                : ['payload_status' => $this->payloadAvailability->value]),
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
            stepIndex: self::intValue($payload, 'step_index'),
            agentClass: self::stringValue($payload, 'agent_class'),
            toolId: self::stringValue($payload, 'tool_id'),
            delta: is_string($payload['delta'] ?? null) ? $payload['delta'] : null,
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
            payloadAvailability: PayloadAvailability::tryFrom(self::stringValue($payload, 'payload_status'))
                ?? PayloadAvailability::Unknown,
        );
    }
}
