<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;

final class SwarmStreamEnd extends SwarmStreamEvent
{
    public readonly CitationEvidence $citationEvidence;

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $runId,
        public ?string $output,
        public array $usage,
        public array $metadata,
        public int $timestamp,
        ?CitationEvidence $citationEvidence = null,
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
            'type' => 'swarm_stream_end',
            'run_id' => $this->runId,
            'output' => $this->output,
            'usage' => $this->usage,
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
            citationEvidence: CitationEvidence::fromArray($payload),
            id: self::stringValue($payload, 'id', self::newId()),
            runId: self::stringValue($payload, 'run_id'),
            output: self::nullableStringValue($payload, 'output'),
            usage: self::arrayValue($payload, 'usage'),
            metadata: self::arrayValue($payload, 'metadata'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
        );
    }
}
