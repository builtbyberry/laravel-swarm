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
 * The in-band set is the CURRENT value plus the PREVIOUS value, so a rolling
 * deploy that straddles a bump never rejects a valid envelope. A value is
 * dropped from the set two minors after the release that introduced its
 * successor (i.e. two minors after it stopped being emitted) — by then no
 * supported rolling-deploy window can still be emitting it.
 *
 * The accept-list is DERIVED, not hand-maintained: it reads
 * {@see EvidenceEnvelope::SCHEMA_VERSION_HISTORY} (version -> introducing minor)
 * and {@see EvidenceEnvelope::CURRENT_MINOR}. This is deliberate — hard-coding a
 * resolved list would silently drop all tolerance on the next bump, defeating
 * the helper's whole purpose. Because the set is computed, a future bump (e.g.
 * "3" -> "4" introduced at some minor M) AUTO-widens the window to accept both
 * "4" and "3" for minors M and M+1, then automatically closes it at M+2.
 *
 * Full version history: "1" (v0.4), "2" (v0.5.0), "3" (v0.12.0). As of the
 * current release the current value "3" has been emitted since v0.12.0 — many
 * minors ago — so "2" is well past its two-minor window and "1" older still.
 * The resolved set today is therefore just `["3"]`, which is correct: a sink
 * receiving "1" or "2" now is reading stale/foreign data, and a tolerant
 * validator that accepts long-dead versions forever defeats the point. See
 * docs/audit-evidence-contract.md ("Versioning") for the authoritative policy.
 */
final class SinkEnvelopeValidator
{
    /**
     * How many minors a superseded `schema_version` value stays in-band after
     * its successor is introduced. "Current + previous for a two-minor window."
     */
    private const ROLLING_WINDOW_MINORS = 2;

    /**
     * The in-band `schema_version` values for the current release.
     *
     * Derived per the supported-versions policy from
     * {@see EvidenceEnvelope::SCHEMA_VERSION_HISTORY} and
     * {@see EvidenceEnvelope::CURRENT_MINOR}: the current value, plus any
     * earlier value whose successor was introduced fewer than
     * {@see self::ROLLING_WINDOW_MINORS} minors before the current release
     * (i.e. still inside its rolling-deploy window).
     *
     * @return array<int, string>
     */
    public static function supportedVersions(): array
    {
        return self::resolveSupportedVersions(
            EvidenceEnvelope::SCHEMA_VERSION,
            EvidenceEnvelope::SCHEMA_VERSION_HISTORY,
            EvidenceEnvelope::CURRENT_MINOR,
        );
    }

    /**
     * Pure resolver for the supported set — the policy encoded as a function of
     * (current value, version->introducing-minor history, current minor).
     *
     * Kept separate from {@see self::supportedVersions()} so the rolling-window
     * behavior can be exercised against a synthetic post-bump history in tests
     * (proving a future bump AUTO-widens the window) without mutating the
     * package-wide {@see EvidenceEnvelope} constants.
     *
     * @param  array<int, int>  $history  version (numeric-string key, stored as int) => package minor it was introduced in
     * @return array<int, string>
     *
     * @internal
     */
    public static function resolveSupportedVersions(string $current, array $history, int $currentMinor): array
    {
        // Ascending by introducing minor, so entry i+1 is the successor of i.
        // A value stops being emitted the moment its successor is introduced;
        // the successor's introducing minor is therefore this value's
        // "superseded-at" minor. It stays in-band until ROLLING_WINDOW_MINORS
        // after that. The current value has no successor and is always in-band.
        asort($history);

        // PHP casts numeric-string array keys to int, so re-stringify the
        // version identifiers — schema_version is a string everywhere else and
        // the strict in_array() in acceptsSchemaVersion() must not see ints.
        $versions = array_map(strval(...), array_keys($history));

        $supported = [$current];

        foreach ($versions as $i => $version) {
            if ($version === $current) {
                continue;
            }

            $successor = $versions[$i + 1] ?? null;

            if ($successor === null) {
                continue;
            }

            $supersededAtMinor = $history[$successor];

            if ($currentMinor - $supersededAtMinor < self::ROLLING_WINDOW_MINORS) {
                $supported[] = $version;
            }
        }

        return array_values(array_unique($supported));
    }

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
        return in_array($version, self::supportedVersions(), strict: true);
    }
}
