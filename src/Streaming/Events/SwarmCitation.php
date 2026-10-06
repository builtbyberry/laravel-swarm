<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;

final class SwarmCitation extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public ?string $messageId,
        public int $timestamp,
        public readonly CitationEvidence $citationEvidence,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $evidence = $this->nodeId !== null ? $this->citationEvidence->withNodeId($this->nodeId) : $this->citationEvidence;

        return [
            'id' => $this->id, 'type' => 'swarm_citation', 'run_id' => $this->runId,
            'step_index' => $this->stepIndex, 'agent_class' => $this->agentClass,
            'invocation_id' => $this->invocationId, 'message_id' => $this->messageId,
            'timestamp' => $this->timestamp, 'node_id' => $this->nodeId,
            ...$this->branchIdentity(),
            ...$evidence->toArray(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(self::stringValue($payload, 'id', self::newId()),
            self::stringValue($payload, 'run_id'), self::intValue($payload, 'step_index'),
            self::stringValue($payload, 'agent_class'), self::nullableStringValue($payload, 'message_id'),
            self::intValue($payload, 'timestamp', self::timestamp()), CitationEvidence::fromArray($payload));
    }
}
