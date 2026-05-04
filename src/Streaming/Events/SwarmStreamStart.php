<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

final class SwarmStreamStart extends SwarmStreamEvent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $runId,
        public string $swarmClass,
        public string $topology,
        public string $input,
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
            'type' => 'swarm_stream_start',
            'run_id' => $this->runId,
            'swarm_class' => $this->swarmClass,
            'topology' => $this->topology,
            'input' => $this->input,
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
            swarmClass: self::stringValue($payload, 'swarm_class'),
            topology: self::stringValue($payload, 'topology'),
            input: self::stringValue($payload, 'input'),
            metadata: self::arrayValue($payload, 'metadata'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
        );
    }
}
