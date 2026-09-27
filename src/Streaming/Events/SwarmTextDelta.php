<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Streaming\PayloadAvailability;

final class SwarmTextDelta extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public ?string $delta,
        public int $timestamp,
        public ?string $messageId = null,
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
            'type' => 'swarm_text_delta',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'delta' => $this->delta,
            ...($this->messageId === null ? [] : ['message_id' => $this->messageId]),
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
            delta: self::nullableStringValue($payload, 'delta'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
            messageId: self::nullableStringValue($payload, 'message_id'),
            payloadAvailability: PayloadAvailability::tryFrom(self::stringValue($payload, 'payload_status')) ?? PayloadAvailability::Unknown,
        );
    }

    /**
     * @param  iterable<int, SwarmStreamEvent>  $events
     */
    public static function combine(iterable $events): string
    {
        $text = '';

        foreach ($events as $event) {
            if ($event instanceof self) {
                $text .= $event->delta ?? '';
            }
        }

        return $text;
    }
}
