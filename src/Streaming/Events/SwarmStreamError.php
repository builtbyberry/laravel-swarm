<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

final class SwarmStreamError extends SwarmStreamEvent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $runId,
        public string $message,
        public ?string $exceptionClass,
        public bool $recoverable,
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
            'type' => 'swarm_stream_error',
            'run_id' => $this->runId,
            'message' => $this->message,
            'exception_class' => $this->exceptionClass,
            'recoverable' => $this->recoverable,
            'metadata' => $this->metadata,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $exceptionClass = self::stringValue($payload, 'exception_class');

        return new self(
            id: self::stringValue($payload, 'id', self::newId()),
            runId: self::stringValue($payload, 'run_id'),
            message: self::stringValue($payload, 'message'),
            exceptionClass: $exceptionClass !== '' ? $exceptionClass : null,
            recoverable: is_bool($payload['recoverable'] ?? null) ? $payload['recoverable'] : false,
            metadata: self::arrayValue($payload, 'metadata'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
        );
    }
}
