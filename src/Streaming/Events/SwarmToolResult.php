<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use Laravel\Ai\Responses\Data\ToolResult;

final class SwarmToolResult extends SwarmStreamEvent
{
    /**
     * @param  class-string  $agentClass
     */
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public ToolResult $toolResult,
        public bool $successful,
        public ?string $error,
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
            'type' => 'swarm_tool_result',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'tool_result' => $this->toolResult->toArray(),
            'successful' => $this->successful,
            'error' => $this->error,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $toolResult = self::arrayValue($payload, 'tool_result');

        return new self(
            id: self::stringValue($payload, 'id', self::newId()),
            runId: self::stringValue($payload, 'run_id'),
            stepIndex: self::intValue($payload, 'step_index'),
            agentClass: self::stringValue($payload, 'agent_class'),
            toolResult: new ToolResult(
                id: self::stringValue($toolResult, 'id'),
                name: self::stringValue($toolResult, 'name'),
                arguments: self::arrayValue($toolResult, 'arguments'),
                result: $toolResult['result'] ?? null,
                resultId: is_string($toolResult['result_id'] ?? null) ? $toolResult['result_id'] : null,
            ),
            successful: is_bool($payload['successful'] ?? null) ? $payload['successful'] : false,
            error: is_string($payload['error'] ?? null) ? $payload['error'] : null,
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
        );
    }
}
