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

    public static function tryFromConfig(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::NullWithLog;
        }

        $normalized = strtolower(trim($value));

        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return $case;
            }
        }

        return self::NullWithLog;
    }
}
