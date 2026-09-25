<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;

final class SwarmStepEnd extends SwarmStreamEvent
{
    public readonly CitationEvidence $citationEvidence;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public string $agent,
        public ?string $output,
        public ?int $durationMs,
        public array $metadata,
        public int $timestamp,
        ?CitationEvidence $citationEvidence = null,
        public ?NativeStepResult $nativeResult = null,
    ) {
        $this->citationEvidence = $citationEvidence ?? new CitationEvidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->citationEvidence->toArray(),
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'node_id' => $this->nodeId,
            'type' => 'swarm_step_end',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'agent' => $this->agent,
            'output' => $this->output,
            'duration_ms' => $this->durationMs,
            'metadata' => $this->metadata,
            'timestamp' => $this->timestamp,
            ...($this->nativeResult !== null ? ['native_result' => $this->nativeResult->toArray()] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            citationEvidence: CitationEvidence::fromArray($payload),
            id: self::stringValue($payload, 'id', self::newId()),
            runId: self::stringValue($payload, 'run_id'),
            stepIndex: self::intValue($payload, 'step_index'),
            agentClass: self::stringValue($payload, 'agent_class'),
            agent: self::stringValue($payload, 'agent'),
            output: self::nullableStringValue($payload, 'output'),
            durationMs: self::nullableIntValue($payload, 'duration_ms'),
            metadata: self::arrayValue($payload, 'metadata'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
            nativeResult: is_array($payload['native_result'] ?? null)
                ? NativeStepResult::fromArray($payload['native_result'])
                : NativeStepResult::unavailable(['legacy']),
        );
    }
}
