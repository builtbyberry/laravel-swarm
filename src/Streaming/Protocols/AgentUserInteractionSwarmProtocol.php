<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Protocols;

use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;

/**
 * Maps SwarmProtocolEvent to a laravel-swarm CUSTOM event and delegates every
 * other event to the inherited protocol implementation.
 *
 * @internal
 *
 * @see AgentUserInteractionProtocol
 */
final class AgentUserInteractionSwarmProtocol extends AgentUserInteractionProtocol
{
    /** @return array<int, array<string, mixed>> */
    protected function mapEvent(StreamEvent $event): array
    {
        if ($event instanceof SwarmProtocolEvent) {
            return [[
                'type' => 'CUSTOM',
                'name' => 'laravel-swarm',
                'value' => $event->payload,
            ]];
        }

        return parent::mapEvent($event);
    }
}
