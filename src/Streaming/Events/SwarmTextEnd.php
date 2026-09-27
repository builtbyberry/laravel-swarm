<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Streaming\PayloadAvailability;

final class SwarmTextEnd extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public string $messageId,
        public int $timestamp,
        public PayloadAvailability $payloadAvailability = PayloadAvailability::Unknown,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            ...$this->transportIdentity(),
            'type' => 'swarm_text_end',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'message_id' => $this->messageId,
            ...($this->payloadAvailability === PayloadAvailability::Unknown ? [] : ['payload_status' => $this->payloadAvailability->value]),
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
            messageId: self::stringValue($payload, 'message_id'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
            payloadAvailability: PayloadAvailability::tryFrom(self::stringValue($payload, 'payload_status')) ?? PayloadAvailability::Unknown,
        );
    }
}
