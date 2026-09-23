<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidenceLimits;
use JsonException;

/** Encrypts only the new citation envelope; other event fields are unchanged. @internal */
final class CitationEvidenceCodec
{
    public function __construct(private SwarmPersistenceCipher $cipher, private CitationEvidenceLimits $limits) {}

    public function encode(CitationEvidence $evidence): ?string
    {
        return $this->cipher->seal(json_encode($this->limits->apply($evidence)->toArray(), JSON_THROW_ON_ERROR));
    }

    public function decode(mixed $value): CitationEvidence
    {
        if ($value === null) {
            return new CitationEvidence;
        }
        if (! is_string($value)) {
            return new CitationEvidence([], CitationEvidence::UNAVAILABLE, ['malformed']);
        }
        [$plain, $available] = $this->cipher->openForDisplay($value);
        if (! $available) {
            return new CitationEvidence([], CitationEvidence::UNAVAILABLE, ['decrypt_failed']);
        }
        try {
            $data = json_decode($plain ?? '', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new CitationEvidence([], CitationEvidence::UNAVAILABLE, ['malformed']);
        }

        return is_array($data) ? $this->limits->apply(CitationEvidence::fromArray($data))
            : new CitationEvidence([], CitationEvidence::UNAVAILABLE, ['malformed']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sealPayload(array $payload): array
    {
        if (! array_key_exists('citation_status', $payload)) {
            return $payload;
        }
        $encoded = $this->encode(CitationEvidence::fromArray($payload));
        unset($payload['citations'], $payload['citation_status'], $payload['citation_reasons']);
        $payload['citation_evidence'] = $encoded;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function openPayload(array $payload): array
    {
        if (! array_key_exists('citation_evidence', $payload)) {
            return $payload;
        }
        $evidence = $this->decode($payload['citation_evidence']);
        unset($payload['citation_evidence'], $payload['citations'], $payload['citation_status'], $payload['citation_reasons']);

        return $payload + $evidence->toArray();
    }
}
