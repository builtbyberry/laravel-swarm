<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Protocols;

use RuntimeException;

/** Executable fixture for the Vercel AI SDK UI message stream state machine. */
final class VercelDataStreamClient
{
    /** @return list<array<string, mixed>> */
    public static function consume(string $stream): array
    {
        $parts = ProtocolStreamFrames::decode($stream, '[DONE]');

        if (($parts[0]['type'] ?? null) !== 'start' || ($parts[1]['type'] ?? null) !== 'start-step') {
            throw new RuntimeException('Vercel stream did not start a UI message and step.');
        }

        $terminal = null;
        foreach ($parts as $index => $part) {
            $type = $part['type'] ?? null;
            if ($terminal !== null) {
                throw new RuntimeException('Vercel stream emitted a frame after its terminal frame.');
            }
            if ($type === 'finish') {
                if (($parts[$index - 1]['type'] ?? null) !== 'finish-step') {
                    throw new RuntimeException('Vercel finish was not preceded by finish-step.');
                }
                $terminal = 'finish';
            } elseif ($type === 'error') {
                $terminal = 'error';
            }
        }

        if ($terminal === null) {
            throw new RuntimeException('Vercel stream had no protocol terminal.');
        }

        return $parts;
    }
}
