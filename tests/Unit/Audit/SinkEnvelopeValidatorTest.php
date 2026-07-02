<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SinkEnvelopeValidator;
use BuiltByBerry\LaravelSwarm\Telemetry\EvidenceEnvelope;

/**
 * Coverage for the tolerant sink-side schema_version helper (issue #50).
 *
 * The helper is a sink convenience — the dispatcher never consults it. It exists
 * so regulated callers stop reimplementing the accept-list by hand.
 */
test('accepts the current envelope schema_version', function (): void {
    expect(SinkEnvelopeValidator::acceptsSchemaVersion(EvidenceEnvelope::SCHEMA_VERSION))->toBeTrue();
});

test('SUPPORTED_VERSIONS always includes the current envelope value', function (): void {
    expect(SinkEnvelopeValidator::SUPPORTED_VERSIONS)->toContain(EvidenceEnvelope::SCHEMA_VERSION);
});

test('supportedVersions() mirrors the SUPPORTED_VERSIONS constant', function (): void {
    expect(SinkEnvelopeValidator::supportedVersions())->toBe(SinkEnvelopeValidator::SUPPORTED_VERSIONS);
});

test('rejects long-dead schema_version values past the drop window', function (): void {
    // "1" (v0.4) and "2" (v0.5.0) are well past the two-minor drop window
    // now that "3" has been emitted since v0.12.0. A tolerant validator is
    // not a forever-accept-list.
    expect(SinkEnvelopeValidator::acceptsSchemaVersion('1'))->toBeFalse()
        ->and(SinkEnvelopeValidator::acceptsSchemaVersion('2'))->toBeFalse();
});

test('rejects unknown, empty, and future values', function (): void {
    expect(SinkEnvelopeValidator::acceptsSchemaVersion(''))->toBeFalse()
        ->and(SinkEnvelopeValidator::acceptsSchemaVersion('nope'))->toBeFalse()
        ->and(SinkEnvelopeValidator::acceptsSchemaVersion('99'))->toBeFalse();
});

test('comparison is strict — an int-like value never loosely matches', function (): void {
    // acceptsSchemaVersion is typed to string, but guard the strictness
    // contract so a refactor can't reintroduce loose in_array matching.
    expect(SinkEnvelopeValidator::acceptsSchemaVersion(' 3'))->toBeFalse()
        ->and(SinkEnvelopeValidator::acceptsSchemaVersion('3.0'))->toBeFalse();
});
