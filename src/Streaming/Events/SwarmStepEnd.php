<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

final class SwarmStepEnd extends SwarmStreamEvent
{
    /**
     * @param  class-string  $agentClass
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public string $agent,
        public string $output,
        public ?int $durationMs,
        public array $metadata,
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
            'type' => 'swarm_step_end',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'agent' => $this->agent,
            'output' => $this->output,
            'duration_ms' => $this->durationMs,
            'metadata' => $this->metadata,
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
            agent: self::stringValue($payload, 'agent'),
            output: self::stringValue($payload, 'output'),
            durationMs: self::nullableIntValue($payload, 'duration_ms'),
            metadata: self::arrayValue($payload, 'metadata'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
        );
    }
}
