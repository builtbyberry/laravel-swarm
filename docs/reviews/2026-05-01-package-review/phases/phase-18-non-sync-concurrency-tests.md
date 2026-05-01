# Phase 18: Non-Sync Concurrency Tests

## Finding

Medium scalability finding: parallel runner tests use the `sync` concurrency
driver, so serialization-boundary behavior is under-validated.

## Current Evidence

- `tests/TestCase.php` sets `concurrency.default` to `sync`.
- Parallel execution uses Laravel's `ConcurrencyManager`.
- Queue and parallel safety checks already focus on container-resolvable agents.

## Decision

Add at least one test that exercises serialization boundaries if the framework
driver is available in CI. If not feasible, add a targeted fake driver that
serializes closures/payloads before execution.

## Implementation Notes

- Investigate Laravel 13 concurrency drivers supported in the test environment.
- Prefer a real supported driver over a custom fake.
- Test that parallel agents receive scalar task/class data and do not depend on
  unserializable instance state.
- Keep tests deterministic and skip only when a required extension is missing.

## Tests

- New concurrency-boundary test.
- Existing parallel runner tests.
- Run `composer test`.

## Docs/Release Notes

- CHANGELOG: test hardening.
- No public docs update unless operator guidance changes.

## Acceptance Criteria

- CI validates more than the synchronous execution path.
- The test fails if parallel execution captures unserializable runtime state.
- Existing sync tests still cover deterministic behavior.

## Follow-up Risk

True fork/process behavior may remain environment-sensitive.
