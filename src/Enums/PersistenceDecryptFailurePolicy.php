<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

enum PersistenceDecryptFailurePolicy: string
{
    /**
     * Log a warning (no ciphertext), return null for the sealed field (default).
     */
    case NullWithLog = 'null_with_log';

    /**
     * Previous behavior: return the stored value unchanged on decrypt failure (opaque ciphertext for sw0: payloads).
     */
    case Legacy = 'legacy';

    /**
     * Propagate decrypt failures to callers.
     */
    case Throw = 'throw';

    /**
     * @return array{policy: self, invalid: bool}
     */
    public static function parse(?string $value): array
    {
        if ($value === null) {
            return ['policy' => self::NullWithLog, 'invalid' => false];
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return ['policy' => self::NullWithLog, 'invalid' => false];
        }

        $normalized = strtolower($trimmed);

        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return ['policy' => $case, 'invalid' => false];
            }
        }

        return ['policy' => self::NullWithLog, 'invalid' => true];
    }

    public static function tryFromConfig(?string $value): self
    {
        return self::parse($value)['policy'];
    }
}
