<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Streaming\PayloadAvailability;

/**
 * A tool call's input has started streaming: the model is writing the arguments
 * for `toolName`, identified by `toolId`, which a later {@see SwarmToolCall} with
 * the same id completes (or a {@see SwarmToolInputError} reports as cut off).
 */
final class SwarmToolInputStart extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public string $toolId,
        public string $toolName,
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
            'type' => 'swarm_tool_input_start',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'tool_id' => $this->toolId,
            'tool_name' => $this->toolName,
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
            toolName: self::stringValue($payload, 'tool_name'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
            payloadAvailability: PayloadAvailability::tryFrom(self::stringValue($payload, 'payload_status'))
                ?? PayloadAvailability::Unknown,
        );
    }
}
