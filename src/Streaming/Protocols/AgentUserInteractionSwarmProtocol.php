<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Protocols;

use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;

/**
 * Adds Swarm workflow CUSTOM events while Laravel AI remains the owner of
 * every standard AG-UI event, response header, error path, and run terminal.
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
