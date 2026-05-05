# Maintenance

Laravel Swarm's database-backed persistence uses prune-based TTL retention.

`ttlSeconds` is still applied when context, artifacts, run history, and
persisted stream replay rows are written, but database records remain queryable
until you prune expired rows.

Swarm tables are **operational workflow storage**. Pruning **deletes** expired
rows; it does not meet immutable audit-log expectations by itself. Teams under
regulated retention requirements should own archival strategy (for example
lifecycle listeners writing to append-only or application-managed audit stores).

## Pruning Expired Records

Use the built-in prune command to remove expired records from the swarm
database tables:

```bash
php artisan swarm:prune
```

Preview how many rows would be deleted **without** deleting:

```bash
php artisan swarm:prune --dry-run
```

Dry-run prints the same category totals as a normal prune (prefixed with “Would
prune”) so operators can judge impact before scheduling aggressive retention.

To disable **destructive** pruning entirely (for example when retention is
handled elsewhere), set `SWARM_PREVENT_PRUNE=true` or
`swarm.retention.prevent_prune` to true in config. Scheduled `swarm:prune`
then exits successfully without deleting rows. **`--dry-run` still runs** so you
can inspect counts while pruning is disabled.

The command prunes the history, context, artifact, stream replay, durable
runtime, durable node state, durable run state, durable node-output, durable
branch, signal, wait, label, detail, progress, child-run, and durable webhook
idempotency tables in bounded chunks to avoid long-running table locks on large
datasets.

Laravel Swarm protects active runs across persistence stores. While a run is
`pending`, `running`, `waiting`, or `paused`, its history, context, artifact,
stream replay, durable runtime, durable node state, durable run state,
node-output, and branch rows are not pruned, even if their retention window has
elapsed.

History pruning only removes expired terminal rows (`completed`, `failed`, and
`cancelled`). Context and artifact pruning skip rows that belong to active runs.
Durable runtime pruning removes terminal runtime rows once their matching
history row is expired.

Durable webhook idempotency pruning removes rows tied to expired terminal run
history. It also removes stale no-run `failed` or `reserved` rows using
`swarm.durable.webhooks.idempotency_ttl`, which is configured through
`SWARM_WEBHOOK_IDEMPOTENCY_TTL`. Completed rows with a `run_id` remain aligned
with durable history retention.

`swarm:prune` is schema-aware. If the history table is missing, the command
skips all pruning because active-run safety depends on history. If history
exists but the context, artifact, or durable runtime table is missing, the
command skips that table role and reports the skip while pruning the tables
that are present. If the durable branch table is missing, branch pruning is
silently skipped so environments that have not run the durable branch migration
can still prune older persistence tables.

If you override `swarm.tables.*`, the prune command respects those configured
table roles directly. It does not rely on default table-name patterns to decide
which rows are safe to delete.

### Foreign-key constraints and prune order

The package migration
`2026_05_04_000001_add_run_id_foreign_keys_to_swarm_tables` adds `ON DELETE CASCADE`
foreign keys from every child table to its parent (`swarm_run_histories` for the
history family, `swarm_durable_runs` for the durable family). The prune command
deletes parents before children, so the cascade fires on already-targeted rows
and does not produce orphan rows or constraint errors.

`swarm_durable_runs.parent_run_id` and `swarm_durable_webhook_idempotency.run_id`
use `ON DELETE SET NULL` so a pruned parent does not block child-run or
idempotency-record retention. `swarm_durable_child_runs.child_run_id` has **no
foreign key**: the referenced durable run may be pruned on its own retention
timeline without affecting the parent's child-run registry.

**Custom table names:** If you publish and rename any of these tables, your
published migration copies must include the equivalent `ON DELETE CASCADE` /
`ON DELETE SET NULL` constraints. Without them, orphan rows can accumulate once
the default FKs are removed from the original table names.

## Migration Notes

Laravel Swarm's package migrations are intentionally simple Laravel migrations.
For most applications the swarm persistence tables are operational tables with
short retention windows, so standard package migration workflows are enough.

**Opting out of migration autoloading**

The package loads its migrations automatically by default regardless of the configured persistence driver. If your application uses only the `cache` persistence driver and you do not want the swarm tables created, call `LaravelSwarm::ignoreMigrations()` from `AppServiceProvider::register()`:

```php
use BuiltByBerry\LaravelSwarm\LaravelSwarm;

public function register(): void
{
    LaravelSwarm::ignoreMigrations();
}
```

This follows the same idiom as Cashier, Sanctum, Passport, Horizon, and Telescope. The `swarm-migrations` publish tag remains available regardless, so the migrations can still be published and customized if needed.

If you later switch to the `database` persistence driver, remove the `ignoreMigrations()` call and run `php artisan migrate`. Alternatively, publish the migrations with `php artisan vendor:publish --tag=swarm-migrations` and manage them from your application's migration directory.

The v0.1.5 migration widens `swarm_contexts.input` from `text` to `longText` so
structured and durable prompts are not truncated by database context
persistence. On a heavily populated MySQL or MariaDB table, even a widening
change can take a table lock depending on engine and version. Run package
migrations during a normal maintenance window if you already have high-volume
swarm context data.

The rollback narrows that column back to `text`. Do not roll it back after
storing prompts larger than the database `text` limit unless you have already
pruned or exported those rows.

## Scheduling

If you are using the database persistence driver in production, schedule the
prune command in Laravel's scheduler:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:prune')->daily();
```

Use `swarm:health` in deployment checks to verify the configured context,
artifact, history, and stream replay stores before application traffic reaches
swarm execution:

```bash
php artisan swarm:health
```

For deployments using `dispatchDurable()` or coordinated multi-worker
hierarchical queueing, include the durable runtime tables:

```bash
php artisan swarm:health --durable
```

If you are using durable execution, also schedule the recovery command
frequently:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyFiveMinutes();
```

The same `swarm:recover` loop redispatches **coordinated queue hierarchical parallel** joins (`coordination_profile = queue_hierarchical_parallel` on `swarm_durable_runs`): stale `waiting` parents with terminal branch rows release to `pending` and enqueue `ResumeQueuedHierarchicalSwarm`, and branch rows are recovered the same way as durable parallel branches.

`swarm:recover` is not a cleanup task like pruning. It is the safety net for
durable runs that were checkpointed successfully but never dispatched their next
step because a worker crashed or exited at the wrong moment. For durable
parallel work, recovery also releases waiting parents whose branch rows are all
terminal, covering the crash window between a branch checkpoint and parent join
dispatch.
For durable run waits, recovery releases timed-out waits back to pending so the
next durable step can observe a timeout outcome.

Prune-based retention is complementary to queue design, not a substitute for
it. The built-in lightweight queue mode is a good fit for normal background
jobs, but very long-running workflows may still outgrow the practical limits of
a single queued job. For those workflows, use `dispatchDurable()` instead of
stretching `queue()` beyond what one job should own.

## High-volume dashboards

Swarm database tables are sized for operational throughput. List and aggregation
queries should use **run history** plus **typed durable columns** and
**satellite tables** (labels, waits, signals, progress, child runs, branches,
`swarm_durable_run_state`, `swarm_durable_node_states`).
Avoid driving dashboards from SQL filters or sorts on arbitrary JSON paths in
checkpoint side tables or the main durable row; that pattern scales poorly and
fights indexing.

See [Operational queries at scale](durable-execution.md#operational-queries-at-scale)
in `docs/durable-execution.md` for the fields the package treats as safe
operational predicates.

## Durable storage growth and archival

Durable execution spreads state across `swarm_durable_runs` (scheduler and lease
columns), `swarm_durable_run_state` (route plan and run-level failure / retry
policy), `swarm_durable_node_states` (per-node snapshots), and existing side
tables for branches, waits, signals, and node outputs. **Prune expired history**
on a schedule so terminal rows and their companion durable tables are reclaimed;
`swarm:prune` already targets these roles in bounded batches.

For regulated or long-retention environments, **pruning is not an audit archive**:
export terminal history, context, artifacts, and any durable side rows you need
for compliance before TTL expiry, or stream lifecycle events into an
append-only application store.

**Partitioning** (for example by time or tenant) is not built into the package.
If a single logical table outgrows comfortable maintenance windows after pruning
and archival, plan an application-specific partitioning or archival tier before
expecting database-native partitioning alone to solve throughput.

## Release Checklist

Before cutting a release tag, work through the checklist in [CONTRIBUTING.md § Release Discipline](../CONTRIBUTING.md#release-discipline).

## Production Checklist

For production database persistence:

- schedule `swarm:prune`
- schedule `swarm:recover` when using durable execution
- treat pruning and recovery as required operating discipline for
  database-backed durable workflows, not optional cleanup
- use a dedicated queue for durable workflows that should not compete with
  ordinary application jobs
- set the queue worker timeout above the longest expected provider call for one
  step, and at or above `AdvanceDurableSwarm` / `AdvanceDurableBranch`
  `timeout()` (`swarm.durable.step_timeout` +
  `swarm.durable.job.timeout_margin_seconds`)
- set the queue connection `retry_after` above the worker timeout and above
  `swarm.durable.step_timeout` (and therefore above the durable advance job
  timeout, which adds the configured margin on top of the step window)
- tune durable advance job retries with `swarm.durable.job.tries` and
  `swarm.durable.job.backoff_seconds` (`SWARM_DURABLE_JOB_TRIES`,
  `SWARM_DURABLE_JOB_BACKOFF_SECONDS`) so transient failures do not fall back to
  queue-worker defaults silently
- keep retention windows short for high-volume or sensitive workflows
- disable automatic artifact capture for cost-sensitive or regulated workflows
  unless step-output artifacts are required for inspection
- monitor run count, step count, artifact count, table growth, and run latency
  after launch

For a conservative enterprise pilot, start with one sequential durable workflow
using lower-sensitivity data, a dedicated queue, short retention, and
conservative capture settings. Hierarchical durable workflows are supported, but
they introduce coordinator prompts, route plans, and intermediate node outputs
as runtime state. Do not begin with broad rollout across document-heavy or
approval-critical workflows until storage growth, recovery behavior, and
operator procedures have been proven in production-like use.
