<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

/** Composes independent evidence envelopes without changing citation serialization. @internal */
final class StreamEventPayloadCodec
{
    public function __construct(private CitationEvidenceCodec $citations, private ProviderToolPayloadCodec $providerTools) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sealPayload(array $payload): array
    {
        return $this->providerTools->sealPayload($this->citations->sealPayload($payload));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function openPayload(array $payload): array
    {
        return $this->providerTools->openPayload($this->citations->openPayload($payload));
    }
}
