# Phase 16: Migration Autoloading

## Finding

Medium package-maintainer finding: package migrations load even when the active
persistence driver is `cache`.

## Current Evidence

- `SwarmServiceProvider::boot()` unconditionally calls `loadMigrationsFrom()`.
- Config defaults `swarm.persistence.driver` to `cache`.
- Migrations are also publishable under the `swarm-migrations` tag.

## Decision

Document the behavior first and evaluate an opt-out flag. Avoid conditional
migration loading that surprises users after they change drivers.

## Implementation Notes

- Add docs explaining that package migrations are available by default even if
  cache persistence is used.
- Consider config such as `swarm.migrations.load` /
  `SWARM_LOAD_MIGRATIONS`, defaulting to current behavior for compatibility.
- If added, ensure published migrations remain available regardless of the flag.
- Do not gate migrations purely on runtime persistence driver; teams may switch
  drivers later.

## Tests

- Service provider tests for default loading and opt-out behavior if implemented.
- Run `composer test` and `composer lint`.

## Docs/Release Notes

- README install/configuration notes.
- `docs/maintenance.md`: migration behavior.
- CHANGELOG: docs or opt-out config.

## Acceptance Criteria

- Operators understand why tables may exist even with cache persistence.
- Any opt-out is explicit and backward compatible.
- Switching to database persistence later remains straightforward.

## Follow-up Risk

Laravel package users generally expect package migrations to be loadable; avoid
over-optimizing this at the cost of convention.
