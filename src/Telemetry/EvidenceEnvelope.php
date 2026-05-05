<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Telemetry;

/**
 * Shared envelope fields for audit evidence and observability telemetry payloads.
 *
 * Pure helpers — no container state. Dispatchers merge these into sink-bound payloads.
 */
final class EvidenceEnvelope
{
    public const SCHEMA_VERSION = '1';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function enrich(string $category, array $payload): array
    {
        return array_merge($payload, [
            'schema_version' => self::SCHEMA_VERSION,
            'category' => $category,
            'occurred_at' => self::occurredAt(),
        ]);
    }

    public static function occurredAt(): string
    {
        return now()->toIso8601String();
    }

    /**
     * Normalize a config-driven metadata allowlist (string, comma-separated string, or array).
     *
     * @return array<int, string>
     */
    public static function normalizeAllowlist(mixed $configured): array
    {
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn (mixed $key): string => trim((string) $key),
                $configured,
            ),
            fn (string $key): bool => $key !== '',
        ));
    }

    /**
     * Return default-safe metadata for evidence/telemetry payloads.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $allowlist
     * @return array{metadata_keys: array<int, string>, metadata: array<string, mixed>}
     */
    public static function metadata(array $metadata, array $allowlist): array
    {
        $keys = array_map('strval', array_keys($metadata));
        sort($keys, SORT_STRING);

        $allowed = [];

        foreach ($allowlist as $key) {
            if (array_key_exists($key, $metadata)) {
                $allowed[$key] = $metadata[$key];
            }
        }

        return [
            'metadata_keys' => $keys,
            'metadata' => $allowed,
        ];
    }
}
