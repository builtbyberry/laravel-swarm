<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Protocols;

use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Protocols\VercelDataProtocol;

/**
 * Adds Swarm workflow data parts while Laravel AI remains the owner of every
 * standard Vercel frame, response header, error path, and stream terminator.
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
