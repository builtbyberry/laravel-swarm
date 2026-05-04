<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

final class SwarmTextDelta extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public string $delta,
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
            'type' => 'swarm_text_delta',
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass,
            'delta' => $this->delta,
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
            delta: self::stringValue($payload, 'delta'),
            timestamp: self::intValue($payload, 'timestamp', self::timestamp()),
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
                $text .= $event->delta;
            }
        }

        return $text;
    }
}
