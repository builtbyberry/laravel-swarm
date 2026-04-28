<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use Laravel\Ai\Responses\Data\ToolCall;

final class SwarmToolCall extends SwarmStreamEvent
{
    /**
     * @param  class-string  $agentClass
     */
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public ToolCall $toolCall,
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
            'type' => 'swarm_tool_call',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'tool_call' => $this->toolCall->toArray(),
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $toolCall = self::arrayValue($payload, 'tool_call');

        return new self(
            id: self::stringValue($payload, 'id', self::newId()),
            runId: self::stringValue($payload, 'run_id'),
            stepIndex: self::intValue($payload, 'step_index'),
            agentClass: self::stringValue($payload, 'agent_class'),
            toolCall: new ToolCall(
                id: self::stringValue($toolCall, 'id'),
                name: self::stringValue($toolCall, 'name'),
                arguments: self::arrayValue($toolCall, 'arguments'),
                resultId: is_string($toolCall['result_id'] ?? null) ? $toolCall['result_id'] : null,
                reasoningId: is_string($toolCall['reasoning_id'] ?? null) ? $toolCall['reasoning_id'] : null,
                reasoningSummary: is_array($toolCall['reasoning_summary'] ?? null) ? $toolCall['reasoning_summary'] : null,
            ),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
        );
    }
}
