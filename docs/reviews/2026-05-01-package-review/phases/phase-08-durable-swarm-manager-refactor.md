# Phase 08: `DurableSwarmManager` Responsibility Split

## Finding

High code-maintainability finding: `DurableSwarmManager` owns too many durable
runtime responsibilities.

## Current Evidence

- `src/Runners/DurableSwarmManager.php` is over 2,000 lines.
- It coordinates leases, step advancement, waits, signals, retries, child
  swarms, labels, progress, hierarchical branches, and events.
- Existing feature tests cover many durable paths and should be preserved.

## Decision

Refactor incrementally behind the existing public manager API. Do not change
public execution methods or durable response contracts in the first slice.

## Implementation Notes

- Extract one low-risk collaborator at a time.
- Start with cohesive operations that already have isolated concepts:
  durable signals/waits, progress/labels/details, child swarms, or retry policy.
- Keep `DurableSwarmManager` as the orchestration facade used by jobs and public
  APIs.
- Avoid broad rewrites of durable step advancement until collaborator seams are
  proven.

## Tests

- Run the durable feature tests after each extraction.
- Run queued hierarchical parallel coordination tests when branch or child
  behavior is touched.
- Run `composer test` and `composer analyse` before merge.

## Docs/Release Notes

- CHANGELOG: internal refactor only if meaningful to contributors.
- No public docs update unless behavior changes.

## Acceptance Criteria

- Public durable APIs and events remain unchanged.
- Extracted collaborator has focused responsibility and direct tests where
  useful.
- Durable tests pass with no deleted coverage.

## Follow-up Risk

The manager will remain large until several slices land. Avoid one massive
refactor PR.
