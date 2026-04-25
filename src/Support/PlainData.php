<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

class PlainData
{
    /**
     * @return array<mixed>
     */
    public static function array(array $value, string $path = 'value'): array
    {
        /** @var array<mixed> $normalized */
        $normalized = self::normalize($value, $path);

        return $normalized;
    }

    public static function value(mixed $value, string $path = 'value'): mixed
    {
        return self::normalize($value, $path);
    }

    protected static function normalize(mixed $value, string $path): mixed
    {
        if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_float($value)) {
            if (is_finite($value)) {
                return $value;
            }

            throw new SwarmException("Swarm plain data value [{$path}] must be a finite float.");
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item, $path.'.'.$key);
            }

            return $normalized;
        }

        throw new SwarmException("Swarm plain data value [{$path}] must be a string, integer, float, boolean, null, or array of plain data.");
    }
}
