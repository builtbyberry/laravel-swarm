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
     * Top-level metadata keys that are always emitted on audit and telemetry
     * payloads regardless of the configured allowlist. The "actor" key is
     * reserved for the resolved Actor identity bound at run entry.
     *
     * @var array<int, string>
     */
    public const RESERVED_METADATA_KEYS = ['actor'];

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

        foreach (self::RESERVED_METADATA_KEYS as $reservedKey) {
            if (array_key_exists($reservedKey, $metadata)) {
                $allowed[$reservedKey] = $metadata[$reservedKey];
            }
        }

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
