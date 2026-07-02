<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SinkEnvelopeValidator;
use BuiltByBerry\LaravelSwarm\Telemetry\EvidenceEnvelope;

/**
 * Coverage for the tolerant sink-side schema_version helper (issue #50).
 *
 * The helper is a sink convenience — the dispatcher never consults it. It exists
 * so regulated callers stop reimplementing the accept-list by hand. The
 * supported set is DERIVED from EvidenceEnvelope's version history + current
 * minor, so a future bump auto-widens the rolling-deploy window; these tests
 * lock that mechanism in.
 */
test('accepts the current envelope schema_version', function (): void {
    expect(SinkEnvelopeValidator::acceptsSchemaVersion(EvidenceEnvelope::SCHEMA_VERSION))->toBeTrue();
});

test('supportedVersions() always includes the current envelope value', function (): void {
    expect(SinkEnvelopeValidator::supportedVersions())->toContain(EvidenceEnvelope::SCHEMA_VERSION);
});

test('acceptsSchemaVersion agrees with supportedVersions()', function (): void {
    foreach (SinkEnvelopeValidator::supportedVersions() as $version) {
        expect(SinkEnvelopeValidator::acceptsSchemaVersion($version))->toBeTrue();
    }
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

test('comparison is strict — a loose-equal value never matches', function (): void {
    expect(SinkEnvelopeValidator::acceptsSchemaVersion(' 3'))->toBeFalse()
        ->and(SinkEnvelopeValidator::acceptsSchemaVersion('3.0'))->toBeFalse();
});

test('today the resolved set is exactly the current value — v2 has aged out', function (): void {
    // Real data: history "3"@12 (current), CURRENT_MINOR 16. "2"'s successor
    // ("3") was introduced at minor 12; 16 - 12 = 4 >= 2, so "2" is out.
    expect(SinkEnvelopeValidator::supportedVersions())->toBe(['3']);
});

/**
 * The rolling window is the whole point of the helper. Exercise it against a
 * synthetic post-bump history so the two-value window the policy promises is
 * actually asserted — not just the single current value (a test that only
 * checked the current value would pass whether the window works or not).
 */
test('a future bump AUTO-widens the window to accept current + previous', function (): void {
    // Simulate "3" -> "4" introduced at minor 17, current release minor 17.
    $history = ['1' => 4, '2' => 5, '3' => 12, '4' => 17];

    // In the introducing minor, "3" was superseded at 17; 17 - 17 = 0 < 2 → in-band.
    expect(SinkEnvelopeValidator::resolveSupportedVersions('4', $history, 17))->toBe(['4', '3']);

    // One minor later (M+1): still 18 - 17 = 1 < 2 → still in-band.
    expect(SinkEnvelopeValidator::resolveSupportedVersions('4', $history, 18))->toBe(['4', '3']);
});

test('the window closes automatically two minors after the successor lands', function (): void {
    $history = ['1' => 4, '2' => 5, '3' => 12, '4' => 17];

    // M+2: 19 - 17 = 2, not < 2 → "3" drops out, leaving just the current value.
    expect(SinkEnvelopeValidator::resolveSupportedVersions('4', $history, 19))->toBe(['4']);
});

test('only the IMMEDIATE previous value is in-band, never one older', function (): void {
    // Right after "3" landed (minor 12), "2" is in-window but "1" (superseded
    // back at minor 5, its successor "2") is not — one-deep window, not all-history.
    $history = ['1' => 4, '2' => 5, '3' => 12];

    expect(SinkEnvelopeValidator::resolveSupportedVersions('3', $history, 12))->toBe(['3', '2']);
});
