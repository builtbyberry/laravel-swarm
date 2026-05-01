# Phase 15: PHPStan Level Increase

## Finding

Medium code-quality finding: PHPStan level 5 is moderate for a package with
generics, closures, dynamic dispatch, and durable runtime state.

## Current Evidence

- `phpstan.neon` sets `level: 5`.
- Composer script already runs the required command:
  `vendor/bin/phpstan analyse --memory-limit=2G --no-progress`.

## Decision

Raise PHPStan incrementally to level 7, fixing discovered issues rather than
masking them broadly.

## Implementation Notes

- Run PHPStan at level 6 and fix meaningful findings.
- Then run at level 7 and fix meaningful findings.
- Add narrow ignores only when Laravel dynamic behavior makes the type
  impossible to express cleanly.
- Keep `treatPhpDocTypesAsCertain: false` unless there is a specific reason to
  change it.

## Tests

- Run `vendor/bin/phpstan analyse --memory-limit=2G --no-progress`.
- Run targeted tests for any code behavior touched during type fixes.
- Run `composer test` if non-trivial code changes occur.

## Docs/Release Notes

- `CONTRIBUTING.md`: update static-analysis expectation.
- CHANGELOG: static-analysis level increase.

## Acceptance Criteria

- PHPStan passes at level 7.
- Ignores are minimal and justified.
- Type fixes do not alter public behavior.

## Follow-up Risk

Level 8 can be evaluated after level 7 remains stable through several changes.
