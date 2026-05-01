# Phase 07: Code Coverage Enforcement

## Finding

High QA finding: CI runs tests but does not enforce code coverage.

## Current Evidence

- `.github/workflows/tests.yml` sets `coverage: none`.
- `composer test` runs Pest without a coverage threshold.
- Durable and queued hierarchical behavior has broad tests, but CI does not
  prove minimum coverage.

## Decision

Introduce a conservative coverage gate that can pass reliably, then raise it as
the suite matures.

## Implementation Notes

- Enable a coverage driver in CI, preferably PCOV for speed.
- Add a Composer script such as `test:coverage`.
- Start with a realistic threshold after measuring current coverage locally.
- If coverage tooling is too slow for every push, run it in a separate CI job.

## Tests

- Run local coverage once to establish the baseline.
- Run normal `composer test`.
- Confirm GitHub Actions syntax is valid.

## Docs/Release Notes

- `CONTRIBUTING.md`: document when to run coverage locally.
- CHANGELOG: CI coverage gate addition.

## Acceptance Criteria

- CI fails when coverage falls below the configured threshold.
- The initial threshold is achievable without weakening the suite.
- Contributors know which command to run.

## Follow-up Risk

Coverage percentage does not prove durable correctness; keep behavior-focused
tests as the primary quality signal.
