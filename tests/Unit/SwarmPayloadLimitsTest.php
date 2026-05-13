<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;

test('checkMetadata passes when metadata is within the configured limit', function () {
    config()->set('swarm.limits.max_metadata_bytes', 1000);

    $limits = app(SwarmPayloadLimits::class);

    $limits->checkMetadata(['tenant_id' => 'acme', 'user' => 'dan']);

    expect(true)->toBeTrue();
});

test('checkMetadata throws SwarmException when metadata exceeds the configured limit', function () {
    config()->set('swarm.limits.max_metadata_bytes', 10);

    $limits = app(SwarmPayloadLimits::class);

    expect(fn () => $limits->checkMetadata(['key' => 'this-value-is-too-large']))
        ->toThrow(SwarmException::class, 'Swarm metadata payload is');
});

test('checkMetadata does not throw when max_metadata_bytes is null regardless of metadata size', function () {
    config()->set('swarm.limits.max_metadata_bytes', null);

    $limits = app(SwarmPayloadLimits::class);

    $largeMetadata = array_fill(0, 100, str_repeat('x', 1000));

    $limits->checkMetadata($largeMetadata);

    expect(true)->toBeTrue();
});

test('checkMetadata error message includes actual byte count and configured limit', function () {
    config()->set('swarm.limits.max_metadata_bytes', 5);

    $limits = app(SwarmPayloadLimits::class);

    $metadata = ['k' => 'v'];
    $expectedBytes = strlen(json_encode($metadata));

    expect(fn () => $limits->checkMetadata($metadata))
        ->toThrow(SwarmException::class, "Swarm metadata payload is {$expectedBytes} bytes, which exceeds the configured 5 byte limit.");
});
