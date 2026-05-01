# Phase 05: Durable JSON Query Scaling

## Finding

High scalability finding: `swarm_durable_runs` stores some operational state in
JSON columns, which can become expensive for dashboard-style queries.

## Current Evidence

- Durable runs include JSON such as `retry_policy`, route/cursor state,
  completed node IDs, node states, and failure metadata.
- Some frequently queried fields already exist as typed/indexed columns:
  `status`, `next_retry_at`, `retry_attempt`, timeout/wait fields, queue fields.
- Dedicated tables already exist for labels, details, progress, child runs,
  waits, signals, branches, and node outputs.

## Decision

Normalize only data that is proven to be queried operationally. Do not
preemptively explode all runtime JSON into relational tables.

## Implementation Notes

- Inventory current `DatabaseDurableRunStore` query predicates.
- Document supported dashboard query surfaces: status, queue, timeout, wait,
  labels, progress, child run status, and timestamps.
- If a missing high-value predicate is found, add a typed column and migration
  with an index.
- Avoid querying arbitrary JSON in package commands.

## Tests

- Add migration tests for any new columns/indexes.
- Add repository tests for any new query surface.
- Run database persistence and durable tests.

## Docs/Release Notes

- `docs/durable-execution.md`: supported operational query fields.
- `docs/maintenance.md`: high-volume dashboard guidance.
- CHANGELOG for any schema or docs changes.

## Acceptance Criteria

- The package documents which durable fields are safe to query at scale.
- No command or documented dashboard path requires broad JSON scans.
- Any new query requirement uses typed/indexed storage.

## Follow-up Risk

Future dashboard product work may require additional normalized projections.
