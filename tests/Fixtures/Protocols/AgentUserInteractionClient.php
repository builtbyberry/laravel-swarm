<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Protocols;

use RuntimeException;

/** Executable fixture for the AG-UI run and step terminal state machine. */
final class AgentUserInteractionClient
{
    /** @return list<array<string, mixed>> */
    public static function consume(string $stream): array
    {
        $events = ProtocolStreamFrames::decode($stream);

        if (($events[0]['type'] ?? null) !== 'RUN_STARTED' || ($events[1]['type'] ?? null) !== 'STEP_STARTED') {
            throw new RuntimeException('AG-UI stream did not start a run and step.');
        }

        $terminal = $events[array_key_last($events)]['type'] ?? null;
        if (! in_array($terminal, ['RUN_FINISHED', 'RUN_ERROR'], true)) {
            throw new RuntimeException('AG-UI stream had no protocol terminal.');
        }

        if ($terminal === 'RUN_FINISHED' && ($events[array_key_last($events) - 1]['type'] ?? null) !== 'STEP_FINISHED') {
            throw new RuntimeException('AG-UI successful run did not finish its step.');
        }

        return $events;
    }
}
