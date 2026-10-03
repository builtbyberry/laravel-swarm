<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Protocols;

use Laravel\Ai\Streaming\Events\StreamEvent;

/** @internal Adapter-only event consumed by the native protocol subclasses. */
final class SwarmProtocolEvent extends StreamEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public array $payload,
        public int $timestamp,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'swarm_protocol_event',
            'payload' => $this->payload,
            'timestamp' => $this->timestamp,
        ];
    }
}
