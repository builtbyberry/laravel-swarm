# Upgrading Laravel Swarm

This guide is the action checklist for upgrading applications that use Laravel
Swarm. Read it when a release asks you to run commands, update published files,
or change application code.

[CHANGELOG.md](CHANGELOG.md) remains the full release history. This file only
records upgrade work an application operator or maintainer may need to perform.

Laravel Swarm follows [semantic versioning](https://semver.org/) for API and
behavior owned by this package. Swarm does not fully isolate your application
from **PHP**, **Laravel**, or **[Laravel AI](https://github.com/laravel/ai)**.
Treat Composer upgrades that touch those dependencies as integration-test events
for your app.

## Upgrade Checklist

Use the normal Laravel package upgrade flow first:

```bash
composer update builtbyberry/laravel-swarm
php artisan config:clear
php artisan migrate
```

If your application caches configuration during deploys, rebuild the cache after
publishing or editing config:

```bash
php artisan config:cache
```

Then run the checks that match how your application uses swarms:

- run your application test suite
- run at least one synchronous `prompt()` path
- run a queued swarm if you call `queue()` or `broadcastOnQueue()`
- run a streamed swarm if you call `stream()`, `broadcast()`, or `broadcastNow()`
- run a durable swarm if you call `dispatchDurable()`
- verify `swarm:status`, `swarm:history`, `swarm:recover`, and `swarm:prune`
  in environments where operators use them

## Published Config And Migrations

Laravel Swarm loads its package migrations by default. If you have not published
or edited the migrations, running `php artisan migrate` is usually enough.

If you published package migrations, compare your copies with the new package
migrations before deploying. Keep table names, indexes, and foreign keys aligned
with your `swarm.tables.*` configuration.

If you published `config/swarm.php`, compare it with the current package config
after each upgrade:

```bash
php artisan vendor:publish --tag=swarm-config --force
```

Do not run that command directly against a production app unless you are ready to
merge your local changes back in. A common workflow is to publish into a clean
branch, review the diff, then copy the new keys or default changes into your
application config.

Pay particular attention to config that changes persistence, capture, queues,
stream replay, durable runtime tables, pruning, and encryption at rest.

## Persistence decrypt failures (minor)

When `swarm.persistence.encrypt_at_rest` is enabled with the database driver,
designated string columns are sealed with Laravel’s encrypter (`APP_KEY`). If the
application key no longer matches the key used when rows were written (for example
after rotation without re-encryption), decryption fails.

Configure **`swarm.persistence.decrypt_failure_policy`** (env
**`SWARM_PERSISTENCE_DECRYPT_FAILURE_POLICY`**):

- **`null_with_log`** (default) — affected sealed **string** fields are returned as
  **null**; a **warning** is logged without ciphertext in the log context.
- **`legacy`** — previous package behavior: return the stored value unchanged (opaque
  `sw0:` ciphertext for sealed payloads).
- **`throw`** — rethrow the decryption exception.

Non-empty values that are **not** one of the above (case-insensitive), including typos,
are treated as **`null_with_log`** for effective runtime behavior. When
**`swarm.persistence.warn_on_invalid_decrypt_failure_policy`** is **`true`** (default,
env **`SWARM_WARN_ON_INVALID_DECRYPT_FAILURE_POLICY`**), Swarm logs **once per worker**
when that misconfiguration is first resolved during a decrypt failure path. Set it to
**`false`** if you cannot emit extra log lines for unknown policy strings.

Encrypt-at-rest applies to **designated string columns** (for example context `input`,
history step I/O). **JSON columns** (`data`, `metadata`, `artifacts`, and similar)
remain structured JSON in the database; do not rely on Swarm to encrypt arbitrary
nested secrets inside JSON unless your application handles that separately.

## Composer minimum-stability

The package uses `"minimum-stability": "dev"` with `"prefer-stable": true` in
[`composer.json`](composer.json). Applications should keep **`prefer-stable` enabled**
so Composer prefers stable releases for transitive dependencies where possible.

## Durable Outbox: Queue Connection Rename Hazard

When a durable run is dispatched, the outbox row stores the `queue_connection`
name that was active at dispatch time. As of the fix for the silent-rerouting
bug, an outbox row whose `queue_connection` no longer matches any key in
`config/queue.connections` is treated as **permanently invalid**: the row is
deleted, the failure is reported to the error tracker, and `swarm:relay` exits
with status 1.

**Impact:** If you rename a queue connection in the same release that deploys
this fix, any outbox rows written under the old connection name will be
permanently deleted when workers running the new code drain them. Before this
fix, those rows were silently dispatched on the application default queue
instead; now they are removed.

**Action required when renaming a queue connection:**

1. Drain the outbox to empty **before** deploying:
   ```bash
   php artisan swarm:relay --drain-until-empty
   ```
2. Confirm the outbox is empty:
   ```bash
   php artisan swarm:health --durable
   ```
3. Deploy the connection rename.

Alternatively, keep the old connection key in `config/queue.connections`
alongside the new one for at least one deploy cycle so that any in-flight rows
can drain normally.

## Durable Outbox: swarm:relay Exit Codes

`swarm:relay` now exits with **status 1** in two cases:

- An unhandled exception escaped the drain loop (was already status 1 in earlier versions via Laravel's command exception handling, but is now emitted before the exception is re-thrown so the audit event is always written).
- One or more entries could not be dispatched due to a **transient error** (queue driver unavailable, network blip). The rows remain in the outbox and will be re-claimed after the reservation timeout; the exit code gives monitoring systems an actionable signal without losing the work.

Exit status 0 now means the outbox is genuinely clean — every claimed entry was
either dispatched or permanently removed. Update any monitoring rules that only
alert on non-zero exits to distinguish the two failure cases using the
`status` field in the `command.relay` audit event (`transient_failure` vs
`error`).

## Contract Changes

Most applications should interact with swarms through Laravel-style public
verbs: `prompt()`, `queue()`, `stream()`, `broadcast()`, `broadcastNow()`,
`broadcastOnQueue()`, and `dispatchDurable()`.

Upgrade notes matter most if your application extends package internals,
implements storage contracts, subclasses database stores, publishes migrations,
or manually resolves runner services. Those extension points can require code
changes even when the application-facing swarm API stays the same.

## Dependency Upgrades

`laravel/ai` is required in the **^0.6** range today and is **pre-1.0**. Public
contracts, streaming behavior, and provider integrations can change between
releases without the stability guarantees of a stable major line.

When upgrading PHP, Laravel, or Laravel AI alongside Swarm:

1. Note the currently resolved versions with `php -v` and `composer show laravel/framework laravel/ai builtbyberry/laravel-swarm`.
2. Update the dependency or constraint in your application.
3. Run `composer update`.
4. Run your automated suite and swarm-heavy smoke paths, especially queued,
   streamed, and durable execution.

You may pin `laravel/ai` to an exact or narrower range in your application’s
`composer.json` when you need reproducible builds or a slower upgrade cadence:

```bash
composer require laravel/ai:0.6.2
```

That pins your application’s dependency resolution. It does not change the semver
range Laravel Swarm declares for Packagist.

This package’s `composer.json` uses `"minimum-stability": "dev"` with
`"prefer-stable": true` so pre-stable dependencies can resolve while Composer
still prefers tagged releases. Your application may need compatible Composer
stability settings while Laravel AI remains pre-stable.

## Upgrading to v0.3.4

No migrations. No breaking changes. Run the standard upgrade flow:

```bash
composer update builtbyberry/laravel-swarm
php artisan config:clear
```

### `make:swarm` now prompts for topology on TTY

`make:swarm` now prompts interactively for topology when run from a TTY without
`--topology`. Existing scripts and CI invocations that piped a topology or
relied on the previous silent default of `sequential` are unaffected:
non-interactive callers (`Artisan::call()`, piped stdin) continue to default to
`sequential`. If you want to preserve the previous one-shot behavior in
interactive shells, pass `--topology=sequential` explicitly.

### Documentation site

The full documentation now lives at https://swarm.builtbyberry.com. The
in-repo `docs/` directory remains the offline mirror. No application action is
required — Composer metadata updates automatically when you upgrade.

## Upgrading to v0.3.3

No migrations. No breaking changes. Run the standard upgrade flow:

```bash
composer update builtbyberry/laravel-swarm
php artisan config:clear
```

### New config key: `static_hierarchical.stream_parallel_branches`

A new key controls how parallel groups behave when a `StaticHierarchical` swarm is
streamed. The package default is `concurrent`.

If you have **published `config/swarm.php`**, add the new block so the key is
configurable from your application config:

```php
'static_hierarchical' => [
    'stream_parallel_branches' => env('SWARM_STATIC_HIERARCHICAL_STREAM_PARALLEL_BRANCHES', 'concurrent'),
],
```

Applications that have not published the config are unaffected — the missing key falls
back to the package default automatically.

See [Static Hierarchical Topology — Streaming](docs/static-hierarchical-topology.md#streaming)
for valid values (`concurrent`, `sequential`) and their behavior.

## Upgrading to v0.3.0

### Transactional outbox and `swarm:relay` scheduler entry

Run `php artisan migrate` after updating the package. Two migrations are added:

- `2026_05_11_000001_create_swarm_durable_outbox_table` — creates `swarm_durable_outbox`
- `2026_05_11_000002_optimize_swarm_durable_outbox_indexes` — replaces the initial composite
  index with two targeted drain indexes (plus a PostgreSQL partial index)

**Action required for all durable swarm users:** add `swarm:relay` to your scheduler. Without
it, durable swarms will stall after every step because the outbox is never drained.

```php
// app/Console/Kernel.php (or routes/console.php in Laravel 11+)
Schedule::command('swarm:relay')->everyMinute();
```

If you also schedule `swarm:recover`, the relay should run at the same or higher frequency.
The relay is a fast, bounded operation (default limit 100 rows per run) and is safe to run
every minute on any database.

To verify the relay is working after deploying, run:

```bash
php artisan swarm:health --durable
```

The **Outbox relay** check warns when unclaimed rows are older than 2× the reservation timeout.
If you see that warning in a healthy environment, it means `swarm:relay` is not scheduled.

**`DurableOutbox::drain()` return type changed**

`DurableOutbox::drain()` now returns `DrainResult` (namespace
`BuiltByBerry\LaravelSwarm\Responses\DrainResult`) instead of `int`. The bundled
`DatabaseDurableOutbox` implementation is updated automatically.

If your application provides a **custom `DurableOutbox` implementation**, update its
`drain()` method signature and return a `new DrainResult(dispatched: $n, skipped: $m)`
instance. The `dispatched` count is entries successfully dispatched to a queue driver;
`skipped` is permanently invalid entries deleted without dispatch.

Applications that only *inject* `DurableOutbox` need no changes.

### `run_id` foreign-key constraints

Run `php artisan migrate` after updating the package. Migration
`2026_05_04_000001_add_run_id_foreign_keys_to_swarm_tables` adds
`ON DELETE CASCADE` foreign keys from every child table to its parent:

- `swarm_contexts`, `swarm_artifacts`, `swarm_run_steps`, `swarm_stream_events`
  → `swarm_run_histories`
- `swarm_durable_branches`, `swarm_durable_node_states`,
  `swarm_durable_run_state`, `swarm_durable_node_outputs`,
  `swarm_durable_signals`, `swarm_durable_waits`, `swarm_durable_labels`,
  `swarm_durable_details`, `swarm_durable_progress` → `swarm_durable_runs`
- `swarm_durable_child_runs.parent_run_id` → `swarm_durable_runs` (CASCADE)
- `swarm_durable_runs.parent_run_id` self-referential (SET NULL)
- `swarm_durable_waits.signal_id` → `swarm_durable_signals` (SET NULL)
- `swarm_durable_webhook_idempotency.run_id` → `swarm_durable_runs` (SET NULL)
- `swarm_durable_child_runs.child_run_id` — **no FK** (independent lifecycle)

Before applying this migration in an existing install, take a database backup
and check each child table for orphaned rows whose parent `run_id` no longer
exists. Foreign-key creation will fail on those rows. Export, delete, or
reconcile orphaned operational records before running `php artisan migrate`.

For general information about the FK contract and prune order, see
[docs/maintenance.md § Foreign-key constraints and prune order](docs/maintenance.md#foreign-key-constraints-and-prune-order).

**Custom table names:** If you have published the package migrations and renamed
any table, run the same orphan checks against your renamed tables and add the
equivalent FK constraints to your published copies. Without them, orphan rows
can accumulate once the parent table is pruned.

### Durable runtime schema split

Run `php artisan migrate` after updating the package. The migration creates
`swarm_durable_node_states` and `swarm_durable_run_state`, migrates existing
`route_plan`, `node_states`, `failure`, and `retry_policy` values from
`swarm_durable_runs`, then drops those columns. `DurableRunStore::find()` and
related inspection APIs keep the same PHP array shape; only the physical layout
changes.

If you override table names, publish `config/swarm.php` and set
`SWARM_DURABLE_NODE_STATES_TABLE` / `SWARM_DURABLE_RUN_STATE_TABLE` when you rename
the new tables.

### `DurableSwarmManager` surface trim

`DurableSwarmManager` no longer exposes `create()`, `dispatchStepJob()`, or
`dispatchBranchJob()`. Run row creation happens through `DurableRunStore` during
the normal start path, and durable step/branch jobs are built by
`BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher`. Typical
application code should keep using `dispatchDurable()` and operator methods on
the manager; see
[docs/durable-runtime-architecture.md](docs/durable-runtime-architecture.md) for
the full map and testing notes.
