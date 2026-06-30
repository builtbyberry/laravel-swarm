<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

/**
 * Author-declared context-growth intent for a streaming swarm (#288).
 *
 * The ladder is a set of cumulative severity bands: a declared policy names the
 * highest rung the framework may take when a run's hot working set exceeds the
 * operator-supplied budget. A higher rung includes the behaviour of every lower
 * one (Backpressure warns and degrades before it delays; Refuse warns before it
 * aborts). The author owns this preference; it is never a correctness invariant.
 *
 * The framework default is {@see self::DegradeToCold} — loud (warn) and
 * least-destructive (nudge background compaction), never blocking or aborting.
 * The operator hard-cap clamps author intent independently: a hard-cap breach
 * refuses regardless of the declared rung.
 */
enum GrowthPolicy: string
{
    /** Take no growth action. The operator hard-cap still applies. */
    case Ignore = 'ignore';

    /** Emit telemetry + a throttled warning when over budget. */
    case Warn = 'warn';

    /** Warn, and nudge background compaction to reclaim what it can (default). */
    case DegradeToCold = 'degrade_to_cold';

    /** Warn, degrade, and insert a bounded delay to slow the producer. */
    case Backpressure = 'backpressure';

    /** Warn, then abort the run loud with a re-dispatchable exception. */
    case Refuse = 'refuse';

    /**
     * Severity rank for cumulative-band comparison. Higher means more aggressive.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Ignore => 0,
            self::Warn => 1,
            self::DegradeToCold => 2,
            self::Backpressure => 3,
            self::Refuse => 4,
        };
    }

    /**
     * Whether this declared ceiling permits acting at the given rung — the
     * cumulative-band rule (a declared rung includes every lower one).
     */
    public function permits(self $rung): bool
    {
        return $this->rank() >= $rung->rank();
    }

    /**
     * Parse an operator-configured value, defaulting to the framework's
     * warn+degrade policy. Mirrors {@see PersistenceDecryptFailurePolicy::parse()}:
     * a null/blank value is the default (not invalid); an unrecognised value
     * falls back to the default and is flagged invalid. The `invalid` flag is
     * exposed for callers that choose to surface a typo'd policy; resolution via
     * {@see tryFromConfig()} (the per-step hot path) intentionally defaults
     * silently rather than risk logging on every step boundary.
     *
     * @return array{policy: self, invalid: bool}
     */
    public static function parse(?string $value): array
    {
        if ($value === null) {
            return ['policy' => self::DegradeToCold, 'invalid' => false];
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return ['policy' => self::DegradeToCold, 'invalid' => false];
        }

        $normalized = strtolower($trimmed);

        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return ['policy' => $case, 'invalid' => false];
            }
        }

        return ['policy' => self::DegradeToCold, 'invalid' => true];
    }

    public static function tryFromConfig(?string $value): self
    {
        return self::parse($value)['policy'];
    }
}
