# Phase 13: `run_id` Foreign Key Constraints

## Finding

Medium database finding: durable child tables use string `run_id` references
without database-level foreign key constraints.

## Current Evidence

- Durable run primary key is `swarm_durable_runs.run_id`.
- Child tables include branches, signals, waits, labels, details, progress,
  child runs, node outputs, and webhook idempotency.
- Table names are configurable via `swarm.tables.*`, and migrations are
  publishable/editable.

## Decision

Evaluate constraints carefully. Add FKs only where they are compatible with
custom table names, existing installs, rollbacks, and prune behavior.

## Implementation Notes

- Inventory all child table relationships and prune order.
- Decide per table between `cascadeOnDelete`, `restrictOnDelete`, or no FK.
- Prefer explicit migration names and defensive rollback.
- Document that published custom migrations may need equivalent constraints.

## Tests

- Migration tests for FK creation and rollback on supported databases.
- Prune tests to ensure deletion order satisfies constraints.
- Run database persistence and durable tests.

## Docs/Release Notes

- `docs/maintenance.md`: FK behavior and custom migration caveat.
- `UPGRADING.md`: migration risk for existing orphaned rows if FKs are added.
- CHANGELOG: schema hardening.

## Acceptance Criteria

- New constraints do not break pruning or durable cleanup.
- Existing installs have a clear upgrade path if orphan rows exist.
- Custom table names remain documented.

## Follow-up Risk

SQLite/MySQL/PostgreSQL constraint behavior differs; keep tests realistic.
