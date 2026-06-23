<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Support\ToolResultEncoding;
use Laravel\Ai\Responses\Data\ToolResult;

final class SwarmToolResult extends SwarmStreamEvent
{
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
        // Degrade at the tool-result boundary, NOT by loosening the strict
        // stream-event store (which also persists non-tool events/checkpoints
        // that must stay strict). A tool result and its arguments are typed
        // `mixed`; if either cannot be JSON-encoded, swap that one value for a
        // typed placeholder + class-only breadcrumb so the strict
        // DatabaseStreamEventStore encode of this event never throws and the
        // live run is not crashed by a single unencodable tool value.
        $toolResult = $this->toolResult->toArray();
        $toolResult['result'] = ToolResultEncoding::degradeToolResult(
            $toolResult['result'] ?? null,
            $this->toolResult->name,
        );

        if (array_key_exists('arguments', $toolResult)) {
            $toolResult['arguments'] = ToolResultEncoding::degradeToolArguments(
                $toolResult['arguments'],
                $this->toolResult->name,
            );
        }

        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'swarm_tool_result',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'tool_result' => $toolResult,
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
