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

        $terminal = null;
        $terminalIndex = null;

        foreach ($events as $index => $event) {
            $type = $event['type'] ?? null;

            if (in_array($type, ['RUN_FINISHED', 'RUN_ERROR'], true)) {
                if ($terminal !== null) {
                    throw new RuntimeException('AG-UI stream emitted more than one protocol terminal.');
                }

                $terminal = $type;
                $terminalIndex = $index;

                continue;
            }

            if ($terminal !== null) {
                throw new RuntimeException('AG-UI stream emitted an event after its protocol terminal.');
            }
        }

        if ($terminal === null || $terminalIndex === null) {
            throw new RuntimeException('AG-UI stream had no protocol terminal.');
        }

        if ($terminal === 'RUN_FINISHED' && ($events[$terminalIndex - 1]['type'] ?? null) !== 'STEP_FINISHED') {
            throw new RuntimeException('AG-UI successful run did not finish its step.');
        }

        return $events;
    }
}
