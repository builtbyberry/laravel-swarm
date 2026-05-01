# Phase 06: Wide Durable Runs Table

## Finding

High database performance finding: `swarm_durable_runs` has grown wide across
multiple migrations, including nullable JSON fields.

## Current Evidence

- Base durable run columns live in
  `2026_04_24_000005_create_swarm_durable_runs_table.php`.
- Later migrations add operational, hierarchical, retry, wait, and coordination
  state.
- Side tables already exist for branches, node outputs, waits, signals, labels,
  details, progress, child runs, and webhook idempotency.

## Decision

Treat the wide row as a targeted refactor candidate, not an immediate breaking
schema rewrite. Move only low-churn or bulky state when there is a concrete
write-amplification or query reason.

## Implementation Notes

- Measure which durable run columns are updated on every step.
- Prefer moving low-churn large JSON such as details or route projections to
  existing side tables where compatible.
- Keep hot scheduler fields on `swarm_durable_runs`: status, lease, queue,
  retry, wait, timeout, and timestamps.
- Add archival guidance before attempting partitioning support.

## Tests

- Migration tests for any column move.
- Durable lifecycle tests to confirm runtime state survives upgrades.
- `composer test` and `composer analyse` after schema changes.

## Docs/Release Notes

- `docs/maintenance.md`: storage growth, archival, and high-volume guidance.
- CHANGELOG: any schema movement or docs-only guidance.
- `UPGRADING.md`: required only for breaking or migration-sensitive changes.

## Acceptance Criteria

- Hot durable workflow updates remain efficient.
- Existing public inspection APIs keep the same response shape.
- Operators get clear archival guidance even before partitioning exists.

## Follow-up Risk

Large-scale deployments may still need application-specific partitioning.
