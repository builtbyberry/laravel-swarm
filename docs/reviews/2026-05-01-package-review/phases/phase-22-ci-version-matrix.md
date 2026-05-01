# Phase 22: CI Version Matrix

## Finding

Low QA finding: CI currently tests a single PHP/Laravel version combination.

## Current Evidence

- `.github/workflows/tests.yml` runs PHP 8.5 with Laravel 13.
- `composer.json` requires PHP `^8.5` and Illuminate `^13.0`.
- There may not be additional supported minor versions yet.

## Decision

Add matrix entries only when they represent supported dependency combinations.
Do not test unsupported versions just to make the matrix look broader.

## Implementation Notes

- Confirm available PHP 8.5 patch/runtime support in GitHub Actions.
- Add lowest/highest dependency update strategy if Composer can resolve both.
- Consider matrix axes for dependency preference rather than unsupported PHP or
  Laravel majors.
- Keep CI duration reasonable.

## Tests

- Validate workflow syntax.
- Let CI prove all matrix entries.
- Locally run `composer test`, `composer analyse`, and `composer lint`.

## Docs/Release Notes

- README compatibility section if matrix expands.
- CHANGELOG: CI matrix update.

## Acceptance Criteria

- CI covers every declared supported version lane that can be tested today.
- The matrix does not imply unsupported Laravel/PHP compatibility.
- Dependency resolution remains deterministic.

## Follow-up Risk

Future Laravel/PHP releases require revisiting Composer constraints and CI.
