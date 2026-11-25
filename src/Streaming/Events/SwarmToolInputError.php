<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Streaming\PayloadAvailability;

/**
 * A tool call whose input started streaming but never completed, for example
 * because the token limit cut it off. The tool was not executed: no
 * {@see SwarmToolCall} or {@see SwarmToolResult} follows for `toolId`. `input` is
 * the partial arguments text received and `error` the reason; both follow output
 * capture (`payloadAvailability`), like the arguments and error of a tool call.
 */
final class SwarmToolInputError extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public string $toolId,
        public string $toolName,
        public ?string $input,
        public ?string $error,
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
            'type' => 'swarm_tool_input_error',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'tool_id' => $this->toolId,
            'tool_name' => $this->toolName,
            'input' => $this->input,
            'error' => $this->error,
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
            input: is_string($payload['input'] ?? null) ? $payload['input'] : null,
            error: is_string($payload['error'] ?? null) ? $payload['error'] : null,
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
            payloadAvailability: PayloadAvailability::tryFrom(self::stringValue($payload, 'payload_status'))
                ?? PayloadAvailability::Unknown,
        );
    }
}
