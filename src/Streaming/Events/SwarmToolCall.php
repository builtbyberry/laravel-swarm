<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use Laravel\Ai\Responses\Data\ToolCall;

final class SwarmToolCall extends SwarmStreamEvent
{
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
            // Pin the nested shape to the fields this event round-trips through
            // fromArray(), rather than delegating to ToolCall::toArray(). This
            // keeps the broadcast/persisted event contract swarm-owned and
            // insulated from upstream provider DTO drift — laravel/ai 0.8 added
            // reasoning_encrypted_content (opaque OpenAI ZDR reasoning), which
            // swarm never echoes back and deliberately omits from its stream.
            'tool_call' => [
                'id' => $this->toolCall->id,
                'name' => $this->toolCall->name,
                'arguments' => $this->toolCall->arguments,
                'result_id' => $this->toolCall->resultId,
                'reasoning_id' => $this->toolCall->reasoningId,
                'reasoning_summary' => $this->toolCall->reasoningSummary,
            ],
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
