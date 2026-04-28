<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

final class SwarmStreamEnd extends SwarmStreamEvent
{
    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $runId,
        public string $output,
        public array $usage,
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
            id: self::stringValue($payload, 'id', self::newId()),
            runId: self::stringValue($payload, 'run_id'),
            output: self::stringValue($payload, 'output'),
            usage: self::arrayValue($payload, 'usage'),
            metadata: self::arrayValue($payload, 'metadata'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
        );
    }
}
