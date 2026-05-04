<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

final class SwarmReasoningEnd extends SwarmStreamEvent
{
    /**
     * @param  array<int|string, mixed>|null  $summary
     */
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public string $reasoningId,
        public int $timestamp,
        public ?array $summary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'swarm_reasoning_end',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'reasoning_id' => $this->reasoningId,
            'timestamp' => $this->timestamp,
            'summary' => $this->summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $summary = $payload['summary'] ?? null;

        return new self(
            id: self::stringValue($payload, 'id', self::newId()),
            runId: self::stringValue($payload, 'run_id'),
            stepIndex: self::intValue($payload, 'step_index'),
            agentClass: self::stringValue($payload, 'agent_class'),
            reasoningId: self::stringValue($payload, 'reasoning_id'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
            summary: is_array($summary) ? $summary : null,
        );
    }
}
