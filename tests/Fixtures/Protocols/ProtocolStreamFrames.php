<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Protocols;

use JsonException;
use RuntimeException;

final class ProtocolStreamFrames
{
    /** @return list<array<string, mixed>> */
    public static function decode(string $stream, ?string $terminator = null): array
    {
        $frames = [];
        $sawTerminator = false;

        foreach (preg_split('/\R\R/', trim($stream)) ?: [] as $frame) {
            if (! str_starts_with($frame, 'data: ')) {
                throw new RuntimeException('Protocol frame was not an SSE data frame.');
            }

            $data = substr($frame, 6);
            if ($terminator !== null && $data === $terminator) {
                $sawTerminator = true;

                continue;
            }

            try {
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Protocol frame was not valid JSON.', previous: $exception);
            }

            if (! is_array($decoded)) {
                throw new RuntimeException('Protocol frame was not a JSON object.');
            }

            $frames[] = $decoded;
        }

        if ($terminator !== null && ! $sawTerminator) {
            throw new RuntimeException('Protocol stream omitted its required terminator.');
        }

        return $frames;
    }
}
