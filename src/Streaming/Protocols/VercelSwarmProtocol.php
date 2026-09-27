<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Protocols;

use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Protocols\VercelDataProtocol;

/**
 * Maps SwarmProtocolEvent to a Vercel data-swarm part and delegates every
 * other event to the inherited protocol implementation.
 *
 * @see VercelDataProtocol
 */
final class VercelSwarmProtocol extends VercelDataProtocol
{
    /** @return array<string, mixed>|null */
    protected function mapEvent(StreamEvent $event): ?array
    {
        if ($event instanceof SwarmProtocolEvent) {
            return ['type' => 'data-swarm', 'data' => $event->payload];
        }

        return parent::mapEvent($event);
    }
}
