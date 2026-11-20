<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Parallel;

/** @internal */
final class ParallelProcessResultEnvelope
{
    /**
     * Decode only the result envelope. A non-null key requires an authenticated
     * frame; a missing or invalid frame never falls back to the legacy JSON form.
     *
     * Wire grammar: https://github.com/laravel/framework/blob/c37133b89b30a86eb404537151edd8944e938f0a/src/Illuminate/Concurrency/ProcessDriver.php
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $output, ?string $key): ?array
    {
        if ($key === null) {
            $decoded = json_decode($output, true);

            return is_array($decoded) ? $decoded : null;
        }

        if ($key === '') {
            return null;
        }

        $marker = '@@LARAVEL-CONCURRENCY-V1@@';
        $length = strlen($output);
        $position = strrpos($output, $marker);

        while ($position !== false) {
            if (preg_match('/\G([A-Za-z0-9+\/=]+)@@([0-9a-f]{64})@@/', $output, $matches, 0, $position + strlen($marker))
                && hash_equals(hash_hmac('sha256', $matches[1], $key), $matches[2])) {
                $payload = base64_decode($matches[1], true);
                $decoded = is_string($payload) ? json_decode($payload, true) : null;

                if (is_array($decoded) && array_key_exists('successful', $decoded)) {
                    return $decoded;
                }
            }

            $position = $position === 0 ? false : strrpos($output, $marker, $position - $length - 1);
        }

        return null;
    }
}
