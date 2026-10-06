<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

use BuiltByBerry\LaravelSwarm\Responses\ProviderToolData;

/** An observed provider activity event; provider status does not assert workflow success. */
final class SwarmProviderToolEvent extends SwarmStreamEvent
{
    public function __construct(
        public string $id,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public string $itemId,
        public string $providerType,
        public string $providerStatus,
        public ?string $provider,
        public int $timestamp,
        public readonly ProviderToolData $payload,
    ) {}

    public function causalId(): string
    {
        return 'provider:'.hash('sha256', serialize([$this->runId, $this->stepIndex, $this->nodeId,
            $this->attemptEpoch, $this->invocationId, $this->id]));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'type' => 'swarm_provider_tool_event', 'run_id' => $this->runId,
            'step_index' => $this->stepIndex, 'agent_class' => $this->agentClass,
            ...$this->transportIdentity(),
            'attempt_epoch' => $this->attemptEpoch, 'causal_id' => $this->causalId(),
            'item_id' => $this->itemId, 'provider_type' => $this->providerType,
            'provider_status' => $this->providerStatus, 'provider' => $this->provider,
            'timestamp' => $this->timestamp, ...$this->payload->toArray(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $event = new self(self::stringValue($payload, 'id'), self::stringValue($payload, 'run_id'),
            self::intValue($payload, 'step_index'), self::stringValue($payload, 'agent_class'),
            self::stringValue($payload, 'item_id'), self::stringValue($payload, 'provider_type'),
            self::stringValue($payload, 'provider_status'), self::nullableStringValue($payload, 'provider'),
            self::intValue($payload, 'timestamp'), ProviderToolData::fromArray($payload));
        $event->invocationId = self::nullableStringValue($payload, 'invocation_id');
        $event->nodeId = self::nullableStringValue($payload, 'node_id');
        $event->attemptEpoch = self::nullableIntValue($payload, 'attempt_epoch');

        return $event;
    }
}
