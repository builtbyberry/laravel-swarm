# Phase 16: Migration Autoloading

## Finding

Medium package-maintainer finding: package migrations load even when the active
persistence driver is `cache`.

## Current Evidence

- `SwarmServiceProvider::boot()` unconditionally calls `loadMigrationsFrom()`.
- Config defaults `swarm.persistence.driver` to `cache`.
- Migrations are also publishable under the `swarm-migrations` tag.

## Decision

Add `LaravelSwarm::ignoreMigrations()` — a static opt-out helper that mirrors
the first-party Laravel idiom used by Cashier, Sanctum, Passport, Horizon, and
Telescope. Default behavior is unchanged (migrations autoload). Cache-only
applications that do not want the swarm tables created call the helper once from
`AppServiceProvider::register()`.

The runtime persistence driver is intentionally not consulted. Gating migrations
on the driver would silently create or skip tables as teams switch drivers, which
is harder to reason about than an explicit opt-out.

## Implementation Notes

- New class `BuiltByBerry\LaravelSwarm\LaravelSwarm` (`src/LaravelSwarm.php`)
  holds `public static bool $runsMigrations = true` and
  `ignoreMigrations(): static`.
- `SwarmServiceProvider::boot()` gates `loadMigrationsFrom()` on
  `LaravelSwarm::$runsMigrations`.
- The `swarm-migrations` publish tag is unconditionally available so users who
  need database persistence later can publish and run migrations regardless of
  whether `ignoreMigrations()` was called.
- Must be called from `register()` (not `boot()`) so it runs before
  `SwarmServiceProvider::boot()`.

## Tests

- `'package migrations are autoloaded by default'` — asserts migrator paths
  contain the package migration directory with default settings.
- `'LaravelSwarm::ignoreMigrations() skips migration autoloading'` — boots a
  fresh provider with `$runsMigrations = false` and asserts no new paths are
  added; resets state in `afterEach`.
- `'swarm-migrations publish tag resolves even when ignoreMigrations() was called'`
  — asserts the publish tag still resolves to the package migration directory
  after `ignoreMigrations()`.
- Run `composer test` and `composer lint`.

## Docs/Release Notes

- README: added "Skipping migrations when using cache persistence" subsection
  with usage example and note about the publish tag remaining available.
- `docs/maintenance.md`: added "Opting out of migration autoloading" paragraph.
- `AGENTS.md`: added `LaravelSwarm` class scope bullet under Key Architecture
  Decisions.
- CHANGELOG: Added entry under Unreleased → Added.

## Acceptance Criteria

- Operators understand why tables may exist even with cache persistence.
- The opt-out is explicit (`ignoreMigrations()`) and backward compatible.
- Switching to database persistence later remains straightforward.

## Follow-up Risk

Laravel package users generally expect package migrations to be loadable; avoid
over-optimizing this at the cost of convention. The publish tag invariant ensures
`ignoreMigrations()` does not strand users who switch drivers.
