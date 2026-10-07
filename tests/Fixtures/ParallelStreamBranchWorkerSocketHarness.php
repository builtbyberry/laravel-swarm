<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use RuntimeException;

/** @internal */
final class ParallelStreamBranchWorkerSocketHarness
{
    /** @var resource|null */
    private static $client = null;

    /** @var resource|null */
    private static $peer = null;

    public static function open(int $acknowledgements = 8): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Could not open the parallel stream worker socket harness.');
        }

        [self::$client, self::$peer] = $pair;
        fwrite(self::$peer, str_repeat("\x06", $acknowledgements));
    }

    /** @return resource|null */
    public static function takeClient()
    {
        $client = self::$client;
        self::$client = null;

        return $client;
    }

    /** @return list<array<string, mixed>> */
    public static function frames(): array
    {
        if (! is_resource(self::$peer)) {
            throw new RuntimeException('The parallel stream worker socket harness is not open.');
        }

        $bytes = stream_get_contents(self::$peer);
        fclose(self::$peer);
        self::$peer = null;
        $frames = [];

        while ($bytes !== '') {
            if (strlen($bytes) < 4) {
                throw new RuntimeException('The parallel stream worker wrote a truncated frame header.');
            }

            $length = unpack('Nlength', substr($bytes, 0, 4))['length'] ?? 0;
            $payload = substr($bytes, 4, $length);
            if (strlen($payload) !== $length) {
                throw new RuntimeException('The parallel stream worker wrote a truncated frame payload.');
            }

            $frame = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($frame)) {
                throw new RuntimeException('The parallel stream worker wrote a non-object frame.');
            }

            $frames[] = $frame;
            $bytes = substr($bytes, 4 + $length);
        }

        return $frames;
    }
}

/**
 * Give the branch worker one end of the deterministic socket harness.
 *
 * @param  resource|null  $context
 * @param  int<0, 7>  $flags
 * @return resource|false
 */
function stream_socket_client(
    string $address,
    ?int &$errorCode = null,
    ?string &$errorMessage = null,
    ?float $timeout = null,
    int $flags = STREAM_CLIENT_CONNECT,
    $context = null,
) {
    $client = ParallelStreamBranchWorkerSocketHarness::takeClient();
    if (is_resource($client)) {
        return $client;
    }

    $timeout ??= (float) ini_get('default_socket_timeout');

    return $context === null
        ? \stream_socket_client($address, $errorCode, $errorMessage, $timeout, $flags)
        : \stream_socket_client($address, $errorCode, $errorMessage, $timeout, $flags, $context);
}
