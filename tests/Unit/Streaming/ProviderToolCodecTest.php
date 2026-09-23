<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Persistence\ProviderToolPayloadCodec;
use BuiltByBerry\LaravelSwarm\Responses\ProviderToolData;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmUnknownEvent;

it('normalizes malformed and legacy provider evidence without leaking arbitrary envelopes', function () {
    $codec = app(ProviderToolPayloadCodec::class);
    $event = new SwarmProviderToolEvent('id', 'run', 0, 'Agent', 'item', 'unknown', 'denied', null, 123, ProviderToolData::capture([]));
    $wire = $event->toArray();
    foreach ([null, 12, 'not-json', '{"data_status":"available","data_reasons":[],"data":"secret"}', '{"data_status":"redacted","data_reasons":[],"data":{"secret":"secret"}}', str_repeat('x', ProviderToolData::MAX_BYTES + 257)] as $invalid) {
        $decoded = $codec->openPayload($wire + ['provider_tool_evidence' => $invalid]);
        expect($decoded)->toMatchArray(['data_status' => 'unavailable', 'data_reasons' => ['malformed'], 'data' => []])
            ->and(json_encode($decoded))->not->toContain('secret', 'provider_tool_evidence');
    }
    unset($wire['data'], $wire['data_status'], $wire['data_reasons']);
    expect(SwarmStreamEvent::fromArray($wire)->payload->status)->toBe('unknown');
    // Unknown event discriminators retain the existing opaque wrapper contract.
    expect(SwarmStreamEvent::fromArray(['type' => 'future_provider_event']))->toBeInstanceOf(SwarmUnknownEvent::class);
});

it('seals only the provider envelope and reads legacy plaintext when encryption is enabled', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $codec = app(ProviderToolPayloadCodec::class);
    $wire = (new SwarmProviderToolEvent('id', 'run', 0, 'Agent', 'item', 'call', 'failed', 'vendor', 123,
        ProviderToolData::capture(['nested' => [false, null, 0, 1.0, 'Ω']])))->toArray();
    $sealed = $codec->sealPayload($wire);
    expect($sealed)->not->toHaveKey('data')->and($sealed['provider_tool_evidence'])->toStartWith('sw0:')
        ->and($codec->openPayload($sealed))->toBe($wire);
    $legacy = $wire;
    unset($legacy['data'], $legacy['data_status'], $legacy['data_reasons']);
    $legacy['provider_tool_evidence'] = json_encode(ProviderToolData::capture([])->toArray());
    expect($codec->openPayload($legacy)['data_status'])->toBe('available');
    expect($codec->sealPayload(['type' => 'swarm_text_delta', 'data' => 'text']))->toBe(['type' => 'swarm_text_delta', 'data' => 'text']);
});
