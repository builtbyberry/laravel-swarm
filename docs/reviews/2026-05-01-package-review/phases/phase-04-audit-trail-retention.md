# Phase 04: Audit Trail Retention and Pruning

## Finding

High compliance finding: run history, step history, and durable runtime records
are mutable and pruneable, which may not meet regulated retention expectations.

## Current Evidence

- `docs/maintenance.md` documents prune-based TTL retention.
- `swarm:prune` removes expired terminal history and durable runtime rows.
- The package is an operational workflow runtime, not a compliance archive.

## Decision

Add explicit retention controls and documentation. Avoid promising immutable
audit logging unless an append-only store is actually implemented.

## Implementation Notes

- Add `swarm:prune --dry-run` to report counts without deleting.
- Add config such as `swarm.retention.prevent_prune` /
  `SWARM_PREVENT_PRUNE` to disable destructive pruning in regulated setups.
- Update `docs/maintenance.md` and `docs/persistence-and-history.md` to state
  that Swarm tables are operational records, not immutable audit logs.
- Recommend event listeners for application-owned immutable audit sinks.

## Tests

- Add feature tests for dry-run output and no deletion.
- Add feature tests for prevent-prune config.
- Run database persistence tests, `composer test`, and `composer lint`.

## Docs/Release Notes

- Maintenance docs: dry-run, prevent-prune, retention responsibilities.
- README production checklist: retention policy and immutable audit sink note.
- CHANGELOG: new prune safeguards.

## Acceptance Criteria

- Operators can inspect prune impact before deletion.
- Regulated deployments can disable package pruning.
- Documentation clearly separates operational history from compliance archives.

## Follow-up Risk

True immutability requires an application-owned append-only storage strategy or a
future dedicated audit integration.
