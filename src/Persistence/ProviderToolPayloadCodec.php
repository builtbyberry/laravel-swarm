<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Responses\ProviderToolData;
use JsonException;

/** @internal */
final class ProviderToolPayloadCodec
{
    public function __construct(private SwarmPersistenceCipher $cipher) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sealPayload(array $payload): array
    {
        if (($payload['type'] ?? null) !== 'swarm_provider_tool_event') {
            return $payload;
        }
        $data = ProviderToolData::fromArray($payload);
        unset($payload['data'], $payload['data_status'], $payload['data_reasons']);
        $payload['provider_tool_evidence'] = $this->cipher->seal(json_encode($data->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        return $payload;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function openPayload(array $payload): array
    {
        if (($payload['type'] ?? null) !== 'swarm_provider_tool_event' || ! array_key_exists('provider_tool_evidence', $payload)) {
            return $payload;
        }
        $evidence = $payload['provider_tool_evidence'];
        unset($payload['provider_tool_evidence'], $payload['data'], $payload['data_status'], $payload['data_reasons']);
        $data = ProviderToolData::withheld('unavailable', 'malformed');
        if (is_string($evidence)) {
            [$plain, $available] = $this->cipher->openForDisplay($evidence);
            if (! $available) {
                $data = ProviderToolData::withheld('unavailable', 'decrypt_failed');
            } elseif (is_string($plain) && strlen($plain) <= ProviderToolData::MAX_BYTES + 256) {
                try {
                    $decoded = json_decode($plain, true, ProviderToolData::MAX_DEPTH + 8, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $data = ProviderToolData::fromArray($decoded);
                    }
                } catch (JsonException) {
                    // Only a static availability reason crosses this boundary.
                }
            }
        }

        return $payload + $data->toArray();
    }
}
