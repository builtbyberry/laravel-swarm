<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Parallel;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;

/** @internal */
final class ParallelStreamProtocol
{
    public const VERSION = 1;

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $frame
     */
    public static function writeFrame($socket, array $frame, int $maxBytes, float $deadline): void
    {
        $payload = json_encode($frame, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $length = strlen($payload);

        if ($length > $maxBytes) {
            throw new SwarmException("Parallel stream frame exceeded the configured [{$maxBytes}] byte limit; branch event and terminal outcome frames are atomic and are not split.");
        }

        self::writeExact($socket, pack('N', $length).$payload, $deadline);
    }

    /**
     * @param  resource  $socket
     * @return array<string, mixed>
     */
    public static function readFrame($socket, int $maxBytes, float $deadline): array
    {
        $header = self::readExact($socket, 4, $deadline);
        $length = unpack('Nlength', $header)['length'] ?? 0;

        if (! is_int($length) || $length < 2 || $length > $maxBytes) {
            throw new SwarmException("Parallel stream transport received an invalid or oversized frame length [{$length}].");
        }

        try {
            $frame = json_decode(self::readExact($socket, $length, $deadline), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SwarmException('Parallel stream transport received malformed JSON.');
        }
        if (! is_array($frame)) {
            throw new SwarmException('Parallel stream transport received a malformed frame.');
        }

        return $frame;
    }

    /** @param resource $socket */
    public static function acknowledge($socket, float $deadline): void
    {
        self::writeExact($socket, "\x06", $deadline);
    }

    /** @param resource $socket */
    public static function awaitAcknowledgement($socket, float $deadline): void
    {
        if (self::readExact($socket, 1, $deadline) !== "\x06") {
            throw new SwarmException('Parallel stream transport received an invalid acknowledgement.');
        }
    }

    /** @param resource $socket */
    private static function writeExact($socket, string $bytes, float $deadline): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            self::wait($socket, false, $deadline);
            $written = fwrite($socket, substr($bytes, $offset));
            if (! is_int($written) || $written < 1) {
                throw new SwarmException('Parallel stream transport disconnected while writing a frame.');
            }
            $offset += $written;
        }
    }

    /** @param resource $socket */
    private static function readExact($socket, int $length, float $deadline): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            self::wait($socket, true, $deadline);
            $chunk = fread($socket, max(1, $length - strlen($bytes)));
            if (! is_string($chunk) || $chunk === '') {
                throw new SwarmException('Parallel stream transport disconnected before a complete frame arrived.');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /** @param resource $socket */
    private static function wait($socket, bool $readable, float $deadline): void
    {
        $remaining = $deadline - (float) hrtime(true);
        if ($remaining <= 0) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout while waiting on parallel stream backpressure.');
        }

        $seconds = intdiv((int) $remaining, 1_000_000_000);
        $microseconds = intdiv((int) $remaining % 1_000_000_000, 1_000);
        $read = $readable ? [$socket] : [];
        $write = $readable ? [] : [$socket];
        $except = [];
        $selected = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($selected === 0) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout while waiting on parallel stream transport.');
        }
        if ($selected === false) {
            throw new SwarmException('Parallel stream transport could not poll its socket.');
        }
    }
}
