<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

/**
 * What Swarm promises when a native tool's handler throws (#634).
 *
 * laravel/ai changed this in v1.4.0 (laravel/ai#1191): a throwing tool no
 * longer ends the run. Swarm's documented contract is the pre-1.4 one, so
 * {@see self::Propagate} is the default and is preserved on every supported
 * laravel/ai version, scoped to swarm runs.
 *
 * The upstream story, the preconditions under which propagation holds, and
 * what each value means for the operator are documented once, in
 * docs/error-handling.md#native-tool-failures. Keep the prose there rather
 * than restating laravel/ai's internals here.
 */
enum NativeToolFailurePolicy: string
{
    /**
     * A failing native tool fails the swarm run, rethrowing the handler's own
     * exception (default). Swarm's documented contract, on every supported
     * laravel/ai version.
     */
    case Propagate = 'propagate';

    /**
     * Defer to laravel/ai. On >= 1.4 the failure is reported and returned to the
     * model as a failed tool result and the run continues. On 1.0-1.3 laravel/ai
     * has no such recovery, so this is indistinguishable from
     * {@see self::Propagate} — Swarm does not synthesise it.
     */
    case Recover = 'recover';

    /**
     * Parse an operator-configured value, defaulting to {@see self::Propagate}.
     * Mirrors {@see GrowthPolicy::parse()} and
     * {@see PersistenceDecryptFailurePolicy::parse()}: a null/blank value is the
     * default (not invalid); an unrecognised value falls back to the default and
     * is flagged invalid.
     *
     * Resolution via {@see tryFromConfig()} defaults silently, and deliberately
     * so: it is read from inside the tool-failure path, where throwing on a
     * typo'd config value would mask the tool's own exception. The `invalid`
     * flag is what `swarm:health` reads to tell an operator their configured
     * value was never in effect, so the silence is confined to the hot path.
     *
     * @return array{policy: self, invalid: bool}
     */
    public static function parse(?string $value): array
    {
        if ($value === null) {
            return ['policy' => self::Propagate, 'invalid' => false];
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return ['policy' => self::Propagate, 'invalid' => false];
        }

        $normalized = strtolower($trimmed);

        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return ['policy' => $case, 'invalid' => false];
            }
        }

        return ['policy' => self::Propagate, 'invalid' => true];
    }

    public static function tryFromConfig(?string $value): self
    {
        return self::parse($value)['policy'];
    }
}
