<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Telemetry\EvidenceEnvelope;

/**
 * Sink-side convenience for tolerant `schema_version` validation.
 *
 * When the audit evidence envelope's {@see EvidenceEnvelope::SCHEMA_VERSION}
 * bumps (most recently "2" -> "3" in v0.12.0), a rolling deploy means sinks can
 * briefly receive payloads carrying either the previous or the current value.
 * The documented advice has always been "validate tolerantly — accept both
 * values during the window", but every regulated caller ends up reimplementing
 * the same accept-list. This helper ships that accept-list once so sinks that
 * branch on `schema_version` can opt in instead of hard-coding a literal.
 *
 * This is a sink-side convenience only. The dispatcher does not consult it, and
 * strict-version sinks (those that pin a single value and reject everything
 * else) remain perfectly valid — using this helper is opt-in.
 *
 * Supported-versions policy
 * -------------------------
 * The in-band set is the CURRENT value plus the PREVIOUS minor's value, so a
 * rolling deploy that straddles a bump never rejects a valid envelope. A value
 * is dropped from the set two minors after the release that introduced it —
 * by then no supported rolling-deploy window can still be emitting it.
 *
 * The full version history is: "1" (v0.4), "2" (v0.5.0), "3" (v0.12.0). "3" has
 * been the emitted value since v0.12.0, many minors ago, so both "1" and "2"
 * are well past the drop window and are intentionally NOT accepted here — a sink
 * receiving them today is reading stale/foreign data, and a tolerant validator
 * that keeps accepting long-dead versions forever defeats the point. If a future
 * bump lands (e.g. "3" -> "4"), the previous value ("3") joins this list for the
 * two-minor window, then drops out. See docs/audit-evidence-contract.md
 * ("Versioning") for the authoritative policy.
 */
final class SinkEnvelopeValidator
{
    /**
     * The in-band `schema_version` values for the current release.
     *
     * Authoritative accept-list per the supported-versions policy above:
     * the current envelope value plus any previous value still inside its
     * two-minor rolling-deploy window. As of v0.12.0 the only in-band value
     * is the current one — every earlier value has aged out.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_VERSIONS = [
        EvidenceEnvelope::SCHEMA_VERSION,
    ];

    /**
     * Whether the given `schema_version` is in-band for this release.
     *
     * Returns true for the current value (and any previous value still inside
     * its supported rolling-deploy window). Sinks that branch on
     * `schema_version` can call this instead of hard-coding an accept-list:
     *
     *   if (! SinkEnvelopeValidator::acceptsSchemaVersion($payload['schema_version'] ?? '')) {
     *       // route to a quarantine / dead-letter path, alert, etc.
     *   }
     */
    public static function acceptsSchemaVersion(string $version): bool
    {
        return in_array($version, self::SUPPORTED_VERSIONS, strict: true);
    }

    /**
     * The supported in-band `schema_version` values for this release.
     *
     * Convenience accessor over {@see self::SUPPORTED_VERSIONS} for callers
     * that prefer a method (e.g. to build their own reporting or to feed a
     * validation message) over reading the constant directly.
     *
     * @return array<int, string>
     */
    public static function supportedVersions(): array
    {
        return self::SUPPORTED_VERSIONS;
    }
}
