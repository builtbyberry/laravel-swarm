# Upgrading Laravel Swarm

This document describes **operational upgrade steps** for applications using Laravel Swarm.

Laravel Swarm follows [semantic versioning](https://semver.org/) for its own API and behavior. **[CHANGELOG.md](CHANGELOG.md)** lists breaking changes, additions, and fixes **owned by this package**.

Swarm does **not** fully isolate you from **PHP**, **Laravel**, or **[Laravel AI](https://github.com/laravel/ai)**. Treat Composer upgrades that touch those dependencies as **integration-test events** for your app. The changelog documents Swarm’s contract; it does not replace verifying behavior against new upstream releases.

## Unreleased: durable runtime schema split

Run `php artisan migrate` after updating the package. The migration creates
`swarm_durable_node_states` and `swarm_durable_run_state`, migrates existing
`route_plan`, `node_states`, `failure`, and `retry_policy` values from
`swarm_durable_runs`, then drops those columns. `DurableRunStore::find()` and
related inspection APIs keep the same PHP array shape; only the physical layout
changes.

If you override table names, publish `config/swarm.php` and set
`SWARM_DURABLE_NODE_STATES_TABLE` / `SWARM_DURABLE_RUN_STATE_TABLE` when you rename
the new tables.

## Unreleased: `DurableSwarmManager` surface trim

`DurableSwarmManager` no longer exposes `create()`, `dispatchStepJob()`, or
`dispatchBranchJob()`. Run row creation happens through `DurableRunStore` during
the normal start path, and durable step/branch jobs are built by
`BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher`. Typical
application code should keep using `dispatchDurable()` and operator methods on
the manager; see
[docs/durable-runtime-architecture.md](docs/durable-runtime-architecture.md) for
the full map and testing notes.

## Upgrading Laravel AI

`laravel/ai` is required in the **^0.6** range today and is **pre-1.0**. Public contracts, streaming behavior, and provider integrations can change between releases without the stability guarantees of a stable major line.

**Recommended flow**

1. Note the currently resolved version (for example `composer show laravel/ai`).
2. Update using `composer update laravel/ai` or by bumping an explicit version constraint in your application’s `composer.json`, then run `composer update`.
3. Run your automated test suite and manual or staging checks for paths that use swarms—especially **queued**, **streamed**, and **durable** runs and any custom agents or tools.

**Pinning in your application**

You may pin `laravel/ai` to an exact or narrower range in **your** application’s `composer.json` (for example `composer require laravel/ai:0.6.2`) when you need reproducible builds or a slower upgrade cadence. That pins **your** dependency resolution; it does not change the semver range Laravel Swarm declares for Packagist.

**Composer stability**

This package’s `composer.json` uses `"minimum-stability": "dev"` with `"prefer-stable": true` so pre-stable dependencies can resolve while preferring tagged releases when available. Your application may need compatible Composer stability settings when transitive packages are pre-stable; see the [README](README.md#installation) installation notes.

When Laravel AI publishes a stable major and Swarm’s constraints evolve, this section will be updated alongside [CHANGELOG.md](CHANGELOG.md).
