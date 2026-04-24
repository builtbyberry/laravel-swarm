<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

class PersistedRunContextMatcher
{
    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $record
     */
    public static function matchesRecord(array $expected, array $record): bool
    {
        $context = is_array($record['context'] ?? null) ? $record['context'] : [];

        return self::matchesContext($expected, $context);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $context
     */
    public static function matchesContext(array $expected, array $context): bool
    {
        $data = is_array($context['data'] ?? null) ? $context['data'] : [];
        $metadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];

        foreach ($expected as $key => $value) {
            if ($key === 'input') {
                if (($context['input'] ?? null) !== $value) {
                    return false;
                }

                continue;
            }

            if ($key === 'metadata') {
                if (! is_array($value) || ! self::arraySubsetMatches($value, $metadata)) {
                    return false;
                }

                continue;
            }

            if (! array_key_exists($key, $data)) {
                return false;
            }

            if (is_array($value)) {
                if (! self::arraySubsetMatches($value, $data[$key])) {
                    return false;
                }

                continue;
            }

            if ($data[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    public static function arraySubsetMatches(array $expected, mixed $actual): bool
    {
        if (! is_array($actual)) {
            return false;
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }

            if (is_array($value)) {
                if (! self::arraySubsetMatches($value, $actual[$key])) {
                    return false;
                }

                continue;
            }

            if ($actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
